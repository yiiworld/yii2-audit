<?php
/**
 * This serves as both the Module for the MVC part of the audit and the configuration/entry point for the actual
 * audit process.
 *
 * @author    Steve Guns <steve@bedezign.com>
 * @package   com.bedezign.yii2.audit
 * @copyright 2014-2015 B&E DeZign
 */

namespace bedezign\yii2\audit;

use Yii;
use yii\base\Application;
use yii\base\Module;
use yii\helpers\ArrayHelper;

/**
 * Class Audit
 * @package bedezign\yii2\audit
 *
 * Audit main module.
 * This module is also responsible for starting the audit process.
 * To configure it you need to do 2 things:
 * - add a module configuration entry:
 *     'modules' => [
 *        'audit' => 'bedezign\yii2\audit\Audit',
 *     ]
 *   or optionally with configuration:
 *     'modules' => [
 *        'audit' => [
 *            'class' => 'bedezign\yii2\audit\Audit',
 *            'ignoreActions' => ['debug/*']
 *     ]
 * - If you want to auto track actions, be sure to add the module to the application bootstrapping:
 *    'bootstrap' => ['audit'],
 *
 */
class Audit extends Module
{
    /**
     * @var string|boolean the layout that should be applied for views within this module. This refers to a view name
     * relative to [[layoutPath]]. If this is not set, it means the layout value of the [[module|parent module]]
     * will be taken. If this is false, layout will be disabled within this module.
     */
    public $layout = 'main';

    /**
     * @var string name of the component to use for database access
     */
    public $db = 'db';

    /**
     * @var string|string[] List of actions to track. '*' is allowed as the last character to use as wildcard.
     */
    public $trackActions = ['*'];

    /**
     * @var string|string[] Actions to ignore. '*' is allowed as the last character to use as wildcard (eg 'debug/*').
     */
    public $ignoreActions = [];

    /**
     * @var int Maximum age (in days) of the audit entries before they are truncated
     */
    public $maxAge = null;

    /**
     * @var int|int[] (List of) user(s) IDs with access to the viewer, null for everyone (if the role matches)
     */
    public $accessUsers = null;

    /**
     * @var string|string[] (List of) role(s) with access to the viewer, null for everyone (if the user matches)
     */
    public $accessRoles = 'admin';

    /**
     * @var bool Compress extra data generated or just keep in text? For people who don't like binary data in the DB
     */
    public $compressData = true;

    /**
     * @var string The callback to use to convert a user id into an identifier (username, email, ...). Can also be html.
     */
    public $userIdentifierCallback = false;

    /**
     * @var array list of panels. If the value is a simple string, it is the identifier of a corePanel to activate (with default settings)
     * If the entry is a '<key>' => '<string>|<array>' it is a new panel that will override the core one.
     * Avialable panels: 'request'
     */
    public $panels = ['request', 'db', 'log', 'asset', 'config', 'mail', 'profiling'];

    /**
     * @var AuditTarget
     */
    public $logTarget;

    /**
     * @var static The current instance
     */
    private static $_current = null;

    /**
     * @var \bedezign\yii2\audit\models\AuditEntry If activated this is the active entry
     */
    private $_entry = null;

    public function init()
    {
        static::$_current = $this;
        parent::init();

        $app = Yii::$app;

        // Allow the users to specify a simple string if there is only 1 entry
        $this->trackActions = ArrayHelper::toArray($this->trackActions);
        $this->ignoreActions = ArrayHelper::toArray($this->ignoreActions);

        if ($this->accessRoles)
            $this->accessRoles = ArrayHelper::toArray($this->accessRoles);

        if ($this->accessUsers)
            $this->accessUsers = ArrayHelper::toArray($this->accessUsers);

        // Before action triggers a new audit entry
        $app->on(Application::EVENT_BEFORE_ACTION, [$this, 'onBeforeAction']);

        // After request finalizes the audit entry.
        $app->on(Application::EVENT_AFTER_REQUEST, [$this, 'onAfterRequest']);

        // Activate the logging target
        $this->logTarget = $app->getLog()->targets['audit'] = new AuditTarget($this);

        $this->initPanels();
    }

    /**
     * Called to evaluate if the current request should be logged
     * @param \yii\base\Event $event
     */
    public function onBeforeAction($event)
    {
        $actionId = $event->action->uniqueId;

        if (count($this->trackActions) && !$this->routeMatches($actionId, $this->trackActions))
            return;

        if (count($this->ignoreActions) && $this->routeMatches($actionId, $this->ignoreActions))
            return;

        // Still here, start audit
        $this->getEntry(true);
    }

    public function onAfterRequest()
    {
        if ($this->entry) {
            $this->_entry->finalize();
        }
    }

    /**
     * Associate extra data with the current entry (if any)
     * @param string $name
     * @param mixed $data The data to associate with the current entry
     * @param string $type Optional type argument
     * @return \bedezign\yii2\audit\models\AuditData
     */
    public function data($name, $data, $type = null)
    {
        $entry = $this->getEntry(false);
        if (!$entry) {
            return;
        }

        $entry->addData($name, $data, $type);
    }

    /**
     * @return \yii\db\Connection the database connection.
     */
    public function getDb()
    {
        return Yii::$app->{$this->db};
    }

    /**
     * Check if the current user has access to the audit functionality
     * @return bool
     * @throws \yii\base\InvalidConfigException
     */
    public function checkAccess()
    {
        if ($this->accessUsers === null && $this->accessRoles === null)
            return true;

        $user = \yii\di\Instance::ensure('user', \yii\web\User::className());
        if ($this->accessUsers && in_array(Yii::$app->user->id, $this->accessUsers))
            return true;

        if ($this->accessRoles) {
            foreach ($this->accessRoles as $role) {
                if ($role === '?') {
                    if ($user->getIsGuest()) return true;
                } elseif ($role === '@') {
                    if (!$user->getIsGuest()) return true;
                } elseif ($user->can($role)) return true;
            }
        }

        return false;
    }

    /**
     * @return array
     */
    public function getAccessControlFilter()
    {
        if ($this->accessUsers === null && $this->accessRoles === null)
            // No user authentication active, skip adding the filter
            return [];

        $rule = ['allow' => 'allow'];
        if ($this->accessRoles) {
            // Add allowed roles
            $rule['roles'] = $this->accessRoles;
        }

        if ($this->accessUsers) {
            $users = $this->accessUsers;
            // Specific users only? Use callback
            $rule['matchCallback'] = function ($rule, $action) use ($users) {
                return in_array(Yii::$app->user->id, $users);
            };
        }

        return ['class' => \yii\filters\AccessControl::className(), 'rules' => [$rule]];
    }

    /**
     * Returns the current module instance.
     * @return static
     */
    public static function current()
    {
        if (!static::$_current) {
            static::$_current = Yii::$app->getModule(Audit::findModuleIdentifier());
        }
        return static::$_current;
    }

    /**
     * @param bool $create
     * @return models\AuditEntry|static
     */
    public function getEntry($create = false)
    {
        if (!$this->_entry && $create) {
            $this->_entry = models\AuditEntry::create(true);
        }
        return $this->_entry;
    }

    public function getUserIdentifier($user_id)
    {
        if (!$user_id) {
            return Yii::t('audit', 'Guest');
        }

        try {
            if ($this->userIdentifierCallback && is_callable($this->userIdentifierCallback))
                return $this->userIdentifierCallback($user_id);
        } catch (\Exception $e) {
        }
        return $user_id;
    }

    public function initPanels($all = false)
    {
        $panels = [];
        $corePanels = $this->corePanels();

        foreach ($this->panels as $key => $value) {
            $identifier = $config = null;
            if (is_numeric($key)) {
                // The config a panel name
                if (!isset($corePanels[$value]))
                    throw new \yii\base\InvalidConfigException("'$value' is not a valid panel name");

                $identifier = $value;
                $config = $corePanels[$value];
            }
            elseif (is_string($key)) {
                $identifier = $key;
                $config = is_string($value) ? ['class' => $value] : $value;
            }

            if (is_array($config)) {
                $config['module'] = $this;
                $config['id'] = $identifier;
                $panels[$identifier] = Yii::createObject($config);
            }
            else
                $panels[$identifier] = $config;
        }

        if ($all) {
            $viewOnlyPanels = [
                'errors'        => ['class' => 'bedezign\yii2\audit\panels\ErrorPanel'],
                'javascript'    => ['class' => 'bedezign\yii2\audit\panels\JavascriptPanel'],
                'trail'         => ['class' => 'bedezign\yii2\audit\panels\TrailPanel'],
            ];

            foreach ($viewOnlyPanels as $identifier => $config)
                if (!isset($panels[$identifier]))
                    $panels[$identifier] = Yii::createObject($config);
        }

        $this->panels = $panels;
    }

    public static function findModuleIdentifier()
    {
        foreach (Yii::$app->modules as $name => $module) {
            $class = null;
            if (is_string($module))
                $class = $module;
            elseif (is_array($module)) {
                if (isset($module['class']) )
                    $class = $module['class'];
            }
            else
                $class = $module::className();

            $parts = explode('\\', $class);
            if ($class && strtolower(end($parts)) == 'audit')
                return $name;
        }
        return null;
    }

    /**
     * Verifies a route against a given list and returns whether it matches or not.
     * Entries in the list are allowed to end with a '*', which means that a substring will be used for the match
     * instead of a full compare.
     *
     * @param string $route An application rout
     * @param string[] $list List of routes to compare against.
     * @return bool
     */
    protected function routeMatches($route, $list)
    {
        foreach ($list as $compare) {
            $len = strlen($compare);
            if ($compare[$len - 1] == '*') {
                $compare = rtrim($compare, '*');
                if (substr($route, 0, $len - 1) === $compare)
                    return true;
            }

            if ($route === $compare)
                return true;
        }
        return false;
    }

    protected function corePanels()
    {
        return [
            'request'       => ['class' => 'bedezign\yii2\audit\panels\RequestPanel'],
            'db'            => ['class' => 'bedezign\yii2\audit\panels\DbPanel'],
            'log'           => ['class' => 'bedezign\yii2\audit\panels\LogPanel'],
            'asset'         => ['class' => 'bedezign\yii2\audit\panels\AssetPanel'],
            'config'        => ['class' => 'bedezign\yii2\audit\panels\ConfigPanel'],
            'mail'          => ['class' => 'bedezign\yii2\audit\panels\MailPanel'],
            'profiling'     => ['class' => 'bedezign\yii2\audit\panels\ProfilingPanel'],
        ];
    }

}
