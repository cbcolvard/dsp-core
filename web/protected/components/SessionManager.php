<?php

/**
 * @category   DreamFactory
 * @package    DreamFactory
 * @subpackage SessionManager
 * @copyright  Copyright (c) 2009 - 2012, DreamFactory (http://www.dreamfactory.com)
 * @license    http://www.dreamfactory.com/license
 */

// 1/10 api calls will cleanup sessions
ini_set('session.gc_divisor', 10);
// 10 minutes for debug
ini_set('session.gc_maxlifetime', 600);

/**
 * Class SessionManager
 */
class SessionManager
{
    /**
     * @var SessionManager
     */
    private static $_instance = null;

    /**
     * @var null
     */
    private static $_userId = null;

    /**
     * @var \CDbConnection
     */
    protected $_sqlConn;

    /**
     * @var int
     */
    protected $_driverType = DbUtilities::DRV_OTHER;

    /**
     *
     */
    public function __construct()
    {
        $this->_sqlConn = Yii::app()->db;
        $this->_driverType = DbUtilities::getDbDriverType($this->_sqlConn);

        session_set_save_handler(
            array($this, 'open'),
            array($this, 'close'),
            array($this, 'read'),
            array($this, 'write'),
            array($this, 'destroy'),
            array($this, 'gc')
        );
    }

    /**
     *
     */
    public function __destruct()
    {
        session_write_close(); // IMPORTANT!
    }

    /**
     * Gets the static instance of this class.
     *
     * @return SessionManager
     */
    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new SessionManager();
        }

        return self::$_instance;
    }

    /**
     * @return bool
     */
    public function open()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function close()
    {
        return true;
    }

    /**
     * @param $id
     * @return mixed|string
     */
    public function read($id)
    {
        try {
            if (!$this->_sqlConn->active)
                $this->_sqlConn->active = true;
            $command = $this->_sqlConn->createCommand();
            $command->select('data')->from('session')->where(array('in', 'id', array($id)));
            $result = $command->queryRow();
            if (!empty($result)) {
                $data = (isset($result['data'])) ? $result['data'] : '';
                if (!empty($data)) {
                    $data = unserialize(base64_decode($data));
                    return $data;
                }
            }
        }
        catch (Exception $ex) {
            error_log($ex->getMessage());
        }

        return '';
    }

    /**
     * @param $id
     * @param $data
     * @return bool
     */
    public function write($id, $data)
    {
        try {
            $data = base64_encode(serialize($data));
            $startTime = time();
            $userId = static::getCurrentUserId();
            $params = array($id, $userId, $startTime, $data);
            switch ($this->_driverType) {
            case DbUtilities::DRV_SQLSRV:
                $sql = "{call UpdateOrInsertSession(?,?,?,?)}";
                break;
            case DbUtilities::DRV_MYSQL:
                $sql = "call UpdateOrInsertSession(?,?,?,?)";
                break;
            default:
                $sql = "call UpdateOrInsertSession(?,?,?,?)";
                break;
            }
            if (!$this->_sqlConn->active)
                $this->_sqlConn->active = true;
            $command = $this->_sqlConn->createCommand($sql);
            $result = $command->execute($params);
            return true;
        }
        catch (Exception $ex) {
            error_log($ex->getMessage());
        }

        return false;
    }

    /**
     * @param $id
     * @return bool
     */
    public function destroy($id)
    {
        try {
            if (!$this->_sqlConn->active)
                $this->_sqlConn->active = true;
            $command = $this->_sqlConn->createCommand();
            $command->delete('session', array('in', 'id', array($id)));
            return true;
        }
        catch (Exception $ex) {
            error_log($ex->getMessage());
        }

        return false;
    }

    /**
     * @param $lifeTime
     * @return bool
     */
    public function gc($lifeTime)
    {
        try {
            $expired = time() - $lifeTime;
            if (!$this->_sqlConn->active)
                $this->_sqlConn->active = true;
            $command = $this->_sqlConn->createCommand();
            $command->delete('session', "start_time < $expired");
            return true;
        }
        catch (Exception $ex) {
            error_log($ex->getMessage());
        }

        return false;
    }
    
    // helper functions

    /**
     * @param $user_ids
     */
    public function deleteSessions($user_ids)
    {
        try {
            if (!$this->_sqlConn->active)
                $this->_sqlConn->active = true;
            $command = $this->_sqlConn->createCommand();
            if (is_array($user_ids)) {
                $command->delete('session', array('in', 'user_id', $user_ids));
            }
            elseif (!empty($user_ids)) {
                $command->delete('session', 'user_id=:id', array(':id'=>$user_ids));
            }
            else {
                // delete all sessions
                $command->delete('session');
            }
        }
        catch (Exception $ex) {
            error_log($ex->getMessage());
        }
    }

    /**
     * @param $user_id
     */
    public function updateSession($user_id)
    {
        try {
            if (!$this->_sqlConn->active)
                $this->_sqlConn->active = true;
            $command = $this->_sqlConn->createCommand();
            $command->select('id')->from('session')->where('user_id=:id', array(':id'=>$user_id));
            $results = $command->queryScalar();
            if (false !== $results) {
                try {
                    $data = static::generateSessionData($user_id);
                    $data = array('public' => $data['public']);
                    $command->reset();
                    $data = base64_encode(serialize($data));
                    $command->update('session', array('data'=>$data), 'user_id=:id', array(':id'=>$user_id));
                }
                catch (Exception $ex) {
                    // delete sessions because something bad happened
                    $command->reset();
                    $command->delete('session', 'user_id=:id', array(':id'=>$user_id));
                }
            }
        }
        catch (Exception $ex) {
            error_log($ex->getMessage());
        }
    }

    /**
     * @param $user_id
     * @param null $user
     * @return array
     * @throws Exception
     */
    public static function generateSessionData($user_id, $user=null)
    {
        if (!isset($user)) {
            $user = User::model()->with('role.role_service_accesses','role.apps','role.services')->findByPk($user_id);
        }
        if (null === $user) {
            throw new Exception("The user with id $user_id does not exist in the system.", ErrorCodes::UNAUTHORIZED);
        }
        $username = $user->getAttribute('username');
        if (!$user->getAttribute('is_active')) {
            throw new Exception("The user with username '$username' is not currently active.", ErrorCodes::FORBIDDEN);
        }

        $isSysAdmin = $user->getAttribute('is_sys_admin');
        $defaultAppId = $user->getAttribute('default_app_id');
        $fields = array('id','display_name','first_name','last_name','username','email','is_sys_admin','last_login_date');
        $userInfo = $user->getAttributes($fields);
        $data = $userInfo; // reply data
        $allowedApps = array();

        if (!$isSysAdmin) {
            $theRole = $user->getRelated('role');
            if (!isset($theRole)) {
                throw new Exception("The user '$username' has not been assigned a role.", ErrorCodes::FORBIDDEN);
            }
            if (!$theRole->getAttribute('is_active')) {
                throw new Exception("The role this user is assigned to is not currently active.", ErrorCodes::FORBIDDEN);
            }

            if (!isset($defaultAppId)) {
                $defaultAppId = $theRole->getAttribute('default_app_id');
            }
            $role = $theRole->attributes;
            $theApps = $theRole->getRelated('apps');
            $roleApps = array();
            if (!empty($theApps)) {
                $appFields = array('id','api_name','is_active');
                foreach($theApps as $app) {
                    $roleApps[] = $app->getAttributes($appFields);
                    if ($app->getAttribute('is_active'))
                        $allowedApps[] = $app;
                }
            }
            $role['apps'] = $roleApps;
            $permsFields = array('service_id','component','access');
            $thePerms = $theRole->getRelated('role_service_accesses');
            $theServices = $theRole->getRelated('services');
            $perms = array();
            foreach ($thePerms as $perm) {
                $permServiceId = $perm->getAttribute('service_id');
                $temp = $perm->getAttributes($permsFields);
                foreach ($theServices as $service) {
                    if ($permServiceId === $service->getAttribute('id')) {
                        $temp['service'] = $service->getAttribute('api_name');
                    }
                }
                $perms[] = $temp;
            }
            $role['services'] = $perms;
            $userInfo['role'] = $role;
        }

        return array('public' => $userInfo,
                     'data' => $data,
                     'allowed_apps'=> $allowedApps,
                     'default_app_id' => $defaultAppId);
    }

    /**
     * @return string
     * @throws Exception
     */
    public static function validateSession()
    {
        if (!isset($_SESSION)) {
            session_start();
        }

        if (!isset($_SESSION['public']) || empty($_SESSION['public'])) {
            throw new Exception("There is no valid session for the current request.", ErrorCodes::UNAUTHORIZED);
        }

        $userId = (isset($_SESSION['public']['id'])) ? $_SESSION['public']['id'] : '';
        if (empty($userId)) {
            throw new Exception("There is no valid user data in the current session.", ErrorCodes::UNAUTHORIZED);
        }
        return $userId;
    }

    /**
     * @param $request
     * @param $service
     * @param string $component
     * @throws Exception
     */
    public static function checkPermission($request, $service, $component = '')
    {
        $userId = static::validateSession();
        $admin = (isset($_SESSION['public']['is_sys_admin'])) ? $_SESSION['public']['is_sys_admin'] : false;
        if ($admin) {
            return; // no need to check role
        }
        $roleInfo = (isset($_SESSION['public']['role'])) ? $_SESSION['public']['role'] : array();
        if (empty($roleInfo)) {
            // no role assigned, if not sys admin, denied service
            throw new Exception("A valid user role or system administrator is required to access services.", ErrorCodes::FORBIDDEN);
        }

        // check if app allowed in role
        $appName = (isset($GLOBALS['app_name'])) ? $GLOBALS['app_name'] : '';
        if (empty($appName)) {
            throw new Exception("A valid application name is required to access services.", ErrorCodes::BAD_REQUEST);
        }

        $apps = Utilities::getArrayValue('apps', $roleInfo, null);
        if (!is_array($apps) || empty($apps)) {
            throw new Exception("Access to application '$appName' is not provisioned for this user's role.", ErrorCodes::FORBIDDEN);
        }
        $found = false;
        foreach ($apps as $app) {
            $temp = Utilities::getArrayValue('api_name', $app);
            if (0 === strcasecmp($appName, $temp)) {
                $found = true;
            }
        }
        if (!$found) {
            throw new Exception("Access to application '$appName' is not provisioned for this user's role.", ErrorCodes::FORBIDDEN);
        }
        /*
             // see if we need to deny access to this app
             $result = $db->retrieveSqlRecordsByIds('app', $appName, 'name', 'id,is_active');
             if ((0 >= count($result)) || empty($result[0])) {
                 throw new Exception("The application '$appName' could not be found.");
             }
             if (!$result[0]['is_active']) {
                 throw new Exception("The application '$appName' is not currently active.");
             }
             $appId = $result[0]['id'];
             // is this app part of the role's allowed apps
             if (!empty($allowedAppIds)) {
                 if (!Utilities::isInList($allowedAppIds, $appId, ',')) {
                     throw new Exception("The application '$appName' is not currently allowed by this role.");
                 }
             }
         */

        $services = Utilities::getArrayValue('services', $roleInfo, null);
        if (!is_array($services) || empty($services)) {
            throw new Exception("Access to service '$service' is not provisioned for this user's role.", ErrorCodes::FORBIDDEN);
        }

        $allAllowed = false;
        $allFound = false;
        $serviceAllowed = false;
        $serviceFound = false;
        foreach ($services as $svcInfo) {
            $theService = Utilities::getArrayValue('service', $svcInfo);
            $theAccess = Utilities::getArrayValue('access', $svcInfo, '');
            if (0 === strcasecmp($service, $theService)) {
                $theComponent = Utilities::getArrayValue('component', $svcInfo);
                if (!empty($component)) {
                    if (0 === strcasecmp($component, $theComponent)) {
                        if (!static::isAllowed($request, $theAccess)) {
                            $msg = ucfirst($request) . " access to component '$component' of service '$service' ";
                            $msg .= "is not allowed by this user's role.";
                            throw new Exception($msg, ErrorCodes::FORBIDDEN);
                        }
                        return; // component specific found and allowed, so bail
                    }
                    elseif (empty($theComponent) || ('*' == $theComponent)) {
                        $serviceAllowed = static::isAllowed($request, $theAccess);
                        $serviceFound = true;
                    }
                }
                else {
                    if (empty($theComponent) || ('*' == $theComponent)) {
                        if (!static::isAllowed($request, $theAccess)) {
                            $msg = ucfirst($request) . " access to service '$service' ";
                            $msg .= "is not allowed by this user's role.";
                            throw new Exception($msg, ErrorCodes::FORBIDDEN);
                        }
                        return; // service specific found and allowed, so bail
                    }
                }
            }
            elseif (empty($theService) || ('*' == $theService)) {
                $allAllowed = static::isAllowed($request, $theAccess);
                $allFound = true;
            }
        }

        if ($serviceFound) {
            if ($serviceAllowed) {
                return; // service found and allowed, so bail
            }
        }
        elseif ($allFound) {
            if ($allAllowed) {
                return; // all services found and allowed, so bail
            }
        }
        $msg = ucfirst($request) . " access to ";
        if (!empty($component))
            $msg .= "component '$component' of ";
        $msg .= "service '$service' is not allowed by this user's role.";
        throw new Exception($msg, ErrorCodes::FORBIDDEN);
    }

    /**
     * @param $request
     * @param $access
     * @return bool
     */
    protected static function isAllowed($request, $access)
    {
        switch ($request) {
        case 'read':
            switch ($access) {
            case 'Read Only':
            case 'Read and Write':
            case 'Full Access':
                return true;
            }
            break;
        case 'create':
            switch ($access) {
            case 'Write Only':
            case 'Read and Write':
            case 'Full Access':
                return true;
            }
            break;
        case 'update':
            switch ($access) {
            case 'Write Only':
            case 'Read and Write':
            case 'Full Access':
                return true;
            }
            break;
        case 'delete':
            switch ($access) {
            case 'Full Access':
                return true;
            }
            break;
        default:
            break;
        }
        return false;
    }

    /**
     * @param $userId
     */
    public static function setCurrentUserId($userId)
    {
        static::$_userId = $userId;
    }

    /**
     * @return null
     */
    public static function getCurrentUserId()
    {
        if (isset(static::$_userId)) return static::$_userId;

        try {
            static::$_userId = static::validateSession();
            return static::$_userId;
        }
        catch (Exception $ex) {
            return null;
        }
    }

    /**
     * @return int|null
     */
    public static function getCurrentRoleId()
    {
        try {
            static::validateSession();
            return (isset($_SESSION['public']['role']['id'])) ? intval($_SESSION['public']['role']['id']) : null;
        }
        catch (Exception $ex) {
            return null;
        }
    }

    /**
     * @return string
     */
    public static function getCurrentAppName()
    {
        return (isset($GLOBALS['app_name'])) ? $GLOBALS['app_name'] : '';
    }

}
