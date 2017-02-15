<?php
namespace ExternalModules;

if (!defined(__DIR__)){
	define(__DIR__, dirname(__FILE__));
}

require_once __DIR__ . "/AbstractExternalModule.php";

if(PHP_SAPI == 'cli'){
	// This is required for redcap when running on the command line (including unit testing).
	define('NOAUTH', true);
}

if(!defined('APP_PATH_WEBROOT')){
	// Only include redcap_connect.php if it hasn't been included at some point before.
	// Upgrades crash without this check.
	// Perhaps it has something to do with loading both the new and old version of redcap_connect.php......
	require_once __DIR__ . "/../../redcap_connect.php";
}

if (class_exists('ExternalModules\ExternalModules')) {
	return;
}

use \Exception;

class ExternalModules
{
	const SYSTEM_SETTING_PROJECT_ID = 'NULL';
	const KEY_VERSION = 'version';
	const KEY_ENABLED = 'enabled';

	const TEST_MODULE_PREFIX = 'UNIT-TESTING-PREFIX';

	const DISABLE_EXTERNAL_MODULE_HOOKS = 'disable-external-module-hooks';

	const OVERRIDE_PERMISSION_LEVEL_SUFFIX = '_override-permission-level';
	const OVERRIDE_PERMISSION_LEVEL_DESIGN_USERS = 'design';

	public static $BASE_URL;
	public static $BASE_PATH;
	public static $MODULES_URL;
	public static $MODULES_PATH;

	private static $initialized = false;
	private static $activeModulePrefix;
	private static $instanceCache = array();
	private static $idsByPrefix;

	private static $systemwideEnabledVersions;
	private static $projectEnabledDefaults;
	private static $projectEnabledOverrides;

	private static $configs = array();

	private static $RESERVED_SETTINGS = array(
		array(
			'key' => self::KEY_VERSION,
			'hidden' => true,
		),
		array(
			'key' => self::KEY_ENABLED,
			'name' => 'Enable on all projects by default',
			'project-name' => 'Enable on this project',
			'type' => 'checkbox',
			'allow-project-overrides' => true,
			'hidden' => false,
                        'default' => 'false',
		)
	);

	private static function isLocalhost()
	{
		return @$_SERVER['HTTP_HOST'] == 'localhost';
	}

        static function getIconURL($icon) {
                $sfx = ".png";
                if (file_exists(self::$BASE_PATH. '/images/' . $icon . $sfx)) {
                        return self::$BASE_URL . '/images/' . $icon . $sfx;
                } else if (file_exists(APP_PATH_DOCROOT . "/Resources/images/" . $icon . $sfx))  {
                        return APP_PATH_IMAGES . $icon . $sfx;
                } else {
                        return $icon . $sfx;
                }
        }

	static function initialize()
	{
		if(self::isLocalhost()){
			// Assume this is a developer's machine and enable errors.
			ini_set('display_errors', 1);
			ini_set('display_startup_errors', 1);
			error_reporting(E_ALL);
		}

		$modulesDirectoryName = '/modules/';

		if(strpos($_SERVER['REQUEST_URI'], $modulesDirectoryName) === 0){
			die('Requests directly to module version directories are disallowed.  Please use the getUrl() method to build urls to your module pages instead.');
		}

		self::$BASE_URL = APP_PATH_WEBROOT . '../external_modules/';
		self::$BASE_PATH = APP_PATH_DOCROOT . '../external_modules/';
		self::$MODULES_PATH = __DIR__ . "/../.." . $modulesDirectoryName;

		if(!self::isLocalhost()){
			register_shutdown_function(function(){
				$activeModulePrefix = self::getActiveModulePrefix();
				if($activeModulePrefix != null){
					$error = error_get_last();
					$message = "The '$activeModulePrefix' module was automatically disabled because of the following error:\n\n";
					$message .= 'Error Message: ' . $error['message'] . "\n";
					$message .= 'File: ' . $error['file'] . "\n";
					$message .= 'Line: ' . $error['line'] . "\n";

					error_log($message);
					ExternalModules::sendAdminEmail("REDCap External Module Automatically Disabled - $activeModulePrefix", $message);

					// We can't just call disable() from here because the database connection has been destroyed.
					// Disable this module via AJAX instead.
					?>
					<br>
					<h4 id="external-modules-message">
						A fatal error occurred while loading the "<?=$activeModulePrefix?>" external module.<br>
						Disabling that module...
					</h4>
					<script src='js/ExternalModules.js'>
					</script>
					<?php
				}
			});
		}
	}

	private static function setActiveModulePrefix($prefix)
	{
		 self::$activeModulePrefix = $prefix;
	}

	private static function getActiveModulePrefix()
	{
		 return self::$activeModulePrefix;
	}

	private static function sendAdminEmail($subject, $message)
	{
		global $project_contact_email;

		$message = str_replace('<br>', "\n", $message);

		$email = new \Message();
		$email->setFrom($project_contact_email);
		$email->setTo('mark.mcever@vanderbilt.edu');
		$email->setSubject($subject);
		$email->setBody($message, true);
		$email->send();
	}

	static function getProjectHeaderPath()
	{
		return APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
	}

	static function getProjectFooterPath()
	{
		return APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
	}

	static function disable($moduleDirectoryPrefix)
	{
		self::removeSystemSetting($moduleDirectoryPrefix, self::KEY_VERSION);
	}

	static function enable($moduleDirectoryPrefix, $version)
	{
		# Attempt to create an instance of the module before enabling it system wide.
		# This should catch problems like syntax errors in module code.
		$instance = self::getModuleInstance($moduleDirectoryPrefix, $version);

		self::initializeSettingDefaults($instance);

		self::setSystemSetting($moduleDirectoryPrefix, self::KEY_VERSION, $version);
	}

	static function initializeSettingDefaults($moduleInstance)
	{
		$config = $moduleInstance->getConfig();
		foreach($config['system-settings'] as $details){
			$key = $details['key'];
			$default = @$details['default'];
			$existingValue = $moduleInstance->getSystemSetting($key);
			if(isset($default) && $existingValue == null){
				$moduleInstance->setSystemSetting($key, $default);
			}
		}
	}

	static function getSystemSetting($moduleDirectoryPrefix, $key)
	{
		return self::getSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key);
	}

	static function getSystemSettings($moduleDirectoryPrefixes, $keys = null)
	{
		return self::getSettings($moduleDirectoryPrefixes, self::SYSTEM_SETTING_PROJECT_ID, $keys);
	}

	static function setSystemSetting($moduleDirectoryPrefix, $key, $value)
	{
		self::setProjectSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key, $value);
	}

	static function removeSystemSetting($moduleDirectoryPrefix, $key)
	{
		self::removeProjectSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key);
	}

	static function setProjectSetting($moduleDirectoryPrefix, $projectId, $key, $value)
	{
		return self::setSetting($moduleDirectoryPrefix, $projectId, $key, $value);
	}

	static function setSystemFileSetting($moduleDirectoryPrefix, $key, $value)
	{
		self::setFileSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key, $value);
	}

	static function setFileSetting($moduleDirectoryPrefix, $projectId, $key, $value)
	{
		self::setSetting($moduleDirectoryPrefix, $projectId, $key, $value, "file");
	}

	static function removeSystemFileSetting($moduleDirectoryPrefix, $key)
	{
		self::removeFileSetting($moduleDirectoryPrefix, self::SYSTEM_SETTING_PROJECT_ID, $key);
	}

	static function removeFileSetting($moduleDirectoryPrefix, $projectId, $key)
	{
		self::setProjectSetting($moduleDirectoryPrefix, $projectId, $key, null);
	}

	public static function isProjectSettingDefined($prefix, $key)
	{
		$config = self::getConfig($prefix);
		foreach($config['project-settings'] as $setting){
			if($setting['key'] == $key){
				return true;
			}
		}

		return false;
	}

	private static function setSetting($moduleDirectoryPrefix, $projectId, $key, $value, $type = "")
	{
		if($projectId == self::SYSTEM_SETTING_PROJECT_ID){
			if(!self::hasSystemSettingsSavePermission($moduleDirectoryPrefix)){
				throw new Exception("You don't have permission to save system settings!");
			}
		}
		else if(!self::hasProjectSettingSavePermission($moduleDirectoryPrefix, $key)) {
			if(self::isProjectSettingDefined($moduleDirectoryPrefix, $key)){
				throw new Exception("You don't have permission to save the following project setting: $key");
			}
			else{
				// The setting is not defined in the config.  Allow any user to save it
				// (effectively leaving permissions up to the module creator).
				// This is required for user based configuration (like reporting for ED Data).
			}
		}

		# if $value is an array, then encode as JSON
		# else store $value as type specified in gettype(...)
		if ($type === "") {
			$type = gettype($value);
		}
		if ($type == "array") {
			$type = "json";
			$value = json_encode($value);
		}

		$externalModuleId = self::getIdForPrefix($moduleDirectoryPrefix);

		$projectId = db_real_escape_string($projectId);
		$key = db_real_escape_string($key);

		# oldValue is not escaped so that null values are maintained to specify an INSERT vs. UPDATE
		$oldValue = self::getSetting($moduleDirectoryPrefix, $projectId, $key);

		$pidString = $projectId;
		if (!$projectId) {
			$pidString = "NULL";
		}

		if ($type == "boolean") {
			$value = ($value) ? 'true' : 'false';
		}
		if (gettype($oldValue) == "boolean") {
			$oldValue = ($oldValue) ? 'true' : 'false';
		}
		# if value is "", it is valid, so proceed on to if #2; if both null, then do nothing
		if(((string) $value === (string) $oldValue) && ($value !== "")){
			// We don't need to do anything.
			return;
		} else if (($value === "") && ($value === $oldValue)) {
			// both empty strings ==> do nothing
			return;
		} else if($value === null){
			$event = "DELETE";
			$sql = "DELETE FROM redcap_external_module_settings
					WHERE
						external_module_id = $externalModuleId
						AND " . self::getSqlEqualClause('project_id', $pidString) . "
						AND `key` = '$key'";
		} else {
			$value = db_real_escape_string($value);
			if($oldValue == null) {
				$event = "INSERT";
				$sql = "INSERT INTO redcap_external_module_settings
							(
								`external_module_id`,
								`project_id`,
								`key`,
								`type`,
								`value`
							)
						VALUES
						(
							$externalModuleId,
							$pidString,
							'$key',
							'$type',
							'$value'
						)";
			} else {
				$event = "UPDATE";
				$sql = "UPDATE redcap_external_module_settings
						SET value = '$value',
							type = '$type'
						WHERE
							external_module_id = $externalModuleId
							AND " . self::getSqlEqualClause('project_id', $projectId) . "
							AND `key` = '$key'";
			}
		}

		self::query($sql);

		$affectedRows = db_affected_rows();

		$description = ucfirst(strtolower($event)) . ' External Module setting';

		if(class_exists('Logging')){
			// REDCap v6.18.3 or later
			\Logging::logEvent($sql, 'redcap_external_module_settings', $event, $key, $value, $description, "", "", $projectId);
		}
		else{
			// REDCap prior to v6.18.3
			log_event($sql, 'redcap_external_module_settings', $event, $key, $value, $description, "", "", $projectId);
		}

		if($affectedRows != 1){
			throw new Exception("Unexpected number of affected rows ($affectedRows) on External Module setting query: $sql");
		}
	}

	static function getProjectSettingsAsArray($moduleDirectoryPrefixes, $projectId)
	{
		$result = self::getSettings($moduleDirectoryPrefixes, array(self::SYSTEM_SETTING_PROJECT_ID, $projectId));

		$settings = array();
		while($row = db_fetch_assoc($result)){
			$key = $row['key'];
			$value = self::transformValueFromDB($row['value']);

			$setting =& $settings[$key];
			if(!isset($setting)){
				$setting = array();
				$settings[$key] =& $setting;
			}

			if($row['project_id'] === null){
				$setting['system_value'] = $value;

				if(!isset($setting['value'])){
					$setting['value'] = $value;
				}
			}
			else{
				$setting['value'] = $value;
		        }
                }

		return $settings;
	}

	static function getSettings($moduleDirectoryPrefixes, $projectIds, $keys = array())
	{
		$whereClauses = array();

		if (!empty($moduleDirectoryPrefixes)) {
			$whereClauses[] = self::getSQLInClause('m.directory_prefix', $moduleDirectoryPrefixes);
		}

		if (!empty($projectIds)) {
			$whereClauses[] = self::getSQLInClause('s.project_id', $projectIds);
		}

		if (!empty($keys)) {
			$whereClauses[] = self::getSQLInClause('s.key', $keys);
		}

		$sql = "SELECT directory_prefix, s.project_id, s.project_id, s.key, s.value
							FROM redcap_external_modules m
							JOIN redcap_external_module_settings s
								ON m.external_module_id = s.external_module_id
							WHERE " . implode(' AND ', $whereClauses);
                return self::query($sql);
	}

	static function validateSettingsRow($row)
	{
		if ($row == null) {
			return null;
		}

		$type = $row['type'];
		$value = $row['value'];

		if ($type == "json") {
			if ($json = json_decode($value)) {
				$value = $json;
			}
		}
		else if ($type == 'file') {
			// do nothing
		}
		else if ($type == "boolean") {
			if ($value == "true") {
				$value = true;
			} else if ($value == "false") {
				$value = false;
			}
		}
		else {
			if (!settype($value, $type)) {
				die('Unable to set the type of "' . $value . '" to "' . $type . '"!  This should never happen, as it means unexpected/inconsistent values exist in the database.');
			}
		}

		$row['value'] = $value;

		return $row;
	}

	private static function getSetting($moduleDirectoryPrefix, $projectId, $key)
	{
		$result = self::getSettings($moduleDirectoryPrefix, $projectId, $key);

		$numRows = db_num_rows($result);
		if($numRows == 1){
			$row = db_fetch_assoc($result);
			return self::transformValueFromDB($row['value']);
		}
		else if($numRows == 0){
			return null;
		}
		else{
			throw new Exception("More than one External Module setting exists for prefix $moduleDirectoryPrefix, project $projectId, and key '$key'!  This should never happen!");
		}
	}

	static function getProjectSetting($moduleDirectoryPrefix, $projectId, $key)
	{
		$value = self::getSetting($moduleDirectoryPrefix, $projectId, $key);

		if($value == null){
			$value =  self::getSystemSetting($moduleDirectoryPrefix, $key);
		}

		return $value;
	}

	static function removeProjectSetting($moduleDirectoryPrefix, $projectId, $key){
		self::setProjectSetting($moduleDirectoryPrefix, $projectId, $key, null);
	}

	private static function getIdForPrefix($prefix)
	{
		if(!isset(self::$idsByPrefix)){
			$result = self::query("SELECT external_module_id, directory_prefix FROM redcap_external_modules");

			$idsByPrefix = array();
			while($row = db_fetch_assoc($result)){
				$idsByPrefix[$row['directory_prefix']] = $row['external_module_id'];
			}

			self::$idsByPrefix = $idsByPrefix;
		}

		$id = @self::$idsByPrefix[$prefix];
		if($id == null){
			self::query("INSERT INTO redcap_external_modules (directory_prefix) VALUES ('$prefix')");
			$id = db_insert_id();
			self::$idsByPrefix[$prefix] = $id;
		}

		return $id;
	}

	public static function getPrefixForID($id){
		$id = db_real_escape_string($id);

		$result = self::query("SELECT directory_prefix FROM redcap_external_modules WHERE external_module_id = '$id'");

		$row = db_fetch_assoc($result);
		if($row){
			return $row['directory_prefix'];
		}

		return null;
	}

	private static function query($sql)
	{
		$result = db_query($sql);

		if($result == FALSE){
			throw new Exception("Error running External Module query: \nDB Error: " . db_error() . "\nSQL: $sql");
		}

		return $result;
	}

	private static function getSQLEqualClause($columnName, $value)
	{
		$columnName = db_real_escape_string($columnName);
		$value = db_real_escape_string($value);

		if($value == 'NULL'){
			return "$columnName IS NULL";
		}
		else{
			return "$columnName = '$value'";
		}
	}

	private static function getSQLInClause($columnName, $array)
	{
		if(!is_array($array)){
			$array = array($array);
		}

		$columnName = db_real_escape_string($columnName);

		$valueListSql = "";
		$nullSql = "";

                foreach($array as $item){
                        if(!empty($valueListSql)){
				        $valueListSql .= ', ';
                        }
        
                        $item = db_real_escape_string($item);

			if($item == 'NULL'){
				$nullSql = "$columnName IS NULL";
			}
			else{
				$valueListSql .= "'$item'";
			}
		}

		$parts = array();

		if(!empty($valueListSql)){
			$parts[] = "$columnName IN ($valueListSql)";
		}

		if(!empty($nullSql)){
			$parts[] = $nullSql;
		}

                return "(" . implode(" OR ", $parts) . ")";
        }

	static function callHook($name, $arguments)
	{
		if(isset($_GET[self::DISABLE_EXTERNAL_MODULE_HOOKS])){
			return;
		}

		if(!defined('PAGE')){
			$page = ltrim($_SERVER['REQUEST_URI'], '/');
			define('PAGE', $page);
		}

		# We must initialize this static class here, since this method actually gets called before anything else.
		# We can't initialize sooner than this because we have to wait for REDCap to initialize it's functions and variables we depend on.
		# This method is actually called many times (once per hook), so we should only initialize once.
		if(!self::$initialized){
			self::initialize();
			self::$initialized = true;
		}

		$name = str_replace('redcap_', '', $name);

		$templatePath = __DIR__ . "/../manager/templates/hooks/$name.php";
		if(file_exists($templatePath)){
			self::safeRequire($templatePath, $arguments);
		}

		$pid = null;
		if(!empty($arguments)){
			$firstArg = $arguments[0];
			if((int)$firstArg == $firstArg){
				// As of REDCap 6.16.8, the above checks allow us to safely assume the first arg is the pid for all hooks.
				$pid = $arguments[0];
			}
		}

		$versionsByPrefix = self::getEnabledModules($pid);
		foreach($versionsByPrefix as $prefix=>$version){
			$methodName = "hook_$name";

			if(!self::hasPermission($prefix, $version, $methodName)){
				// To prevent unnecessary class conflicts (especially with old plugins), we should avoid loading any module classes that don't actually use this hook.
				continue;
			}

			$instance = self::getModuleInstance($prefix, $version);
			if(method_exists($instance, $methodName)){
				self::setActiveModulePrefix($prefix);
				try{
					call_user_func_array(array($instance,$methodName), $arguments);
				}
				catch(Exception $e){
					$message = "The '" . $prefix . "' module threw the following exception when calling the hook method '$methodName':\n\n" . $e;
					error_log($message);
					ExternalModules::sendAdminEmail("REDCap External Module Hook Exception - $prefix", $message);
				}
				self::setActiveModulePrefix(null);
			}
		}
	}

	# This function exists solely to provide a scope where we don't care if local variables get overwritten by code in the required file.
	# Use the $arguments variable to pass data to the required file.
	static function safeRequire($path, $arguments = array()){
		require $path;
	}

	# This function exists solely to provide a scope where we don't care if local variables get overwritten by code in the required file.
	# Use the $arguments variable to pass data to the required file.
	static function safeRequireOnce($path, $arguments = array()){
		require_once $path;
	}

	private static function getModuleInstance($prefix, $version)
	{
		self::setActiveModulePrefix($prefix);

		$moduleDirectoryName = self::getModuleDirectoryName($prefix, $version);
		$instance = null;
		if (isset(self::$instanceCache[$moduleDirectoryName])) {
			$instance = @self::$instanceCache[$moduleDirectoryName];
		}
		if(!isset($instance)){
			$modulePath = ExternalModules::$MODULES_PATH . $moduleDirectoryName;
			$className = self::getMainClassName($prefix);
			$classNameWithNamespace = "\\" . __NAMESPACE__ . "\\$className";

			if(!class_exists($classNameWithNamespace)){
				$classFilePath = "$modulePath/$className.php";

				if(!file_exists($classFilePath)){
					throw new Exception("Could not find the following External Module main class file: $classFilePath");
				}

				self::safeRequireOnce($classFilePath);
			}

			$instance = new $classNameWithNamespace();
			self::$instanceCache[$moduleDirectoryName] = $instance;
		}

		self::setActiveModulePrefix(null);

		return $instance;
	}

	private static function getMainClassName($prefix)
	{
		$parts = explode('_', $prefix);
		$parts = explode('-', $parts[1]);

		$className = '';
		foreach($parts as $part){
			$className .= ucfirst($part);
		}

		$className .= 'ExternalModule';

		return $className;
	}

	// Accepts a project id as the first parameter.
	// If the project id is null, all systemwide enabled module instances are returned.
	// Otherwise, only instances enabled for the current project id are returned.
	static function getEnabledModules($pid = null)
	{
		if($pid == null){
			return self::getGloballyEnabledVersions();
		}
		else{
			return self::getEnabledModuleVersionsForProject($pid);
		}
	}

	private static function getSystemwideEnabledVersions()
	{
		if(!isset(self::$systemwideEnabledVersions)){
			self::cacheAllEnableData();
		}

		return self::$systemwideEnabledVersions;
	}

	private static function getProjectEnabledDefaults()
	{
		if(!isset(self::$projectEnabledDefaults)){
			self::cacheAllEnableData();
		}

		return self::$projectEnabledDefaults;
	}

	private static function getProjectEnabledOverrides()
	{
		if(!isset(self::$projectEnabledOverrides)){
			self::cacheAllEnableData();
		}

		return self::$projectEnabledOverrides;
	}

	private static function getEnabledModuleVersionsForProject($pid)
	{
                // look for UNIT-TESTING-PREFIX here
		$projectEnabledOverrides = self::getProjectEnabledOverrides();

		$enabledPrefixes = self::getProjectEnabledDefaults();
		$overrides = @$projectEnabledOverrides[$pid];
		if(isset($overrides)){
			foreach($overrides as $prefix => $value){
				if($value == 1){
					$enabledPrefixes[$prefix] = true;
				}
				else{
					unset($enabledPrefixes[$prefix]);
				}
			}
		}
                if ($b) {
                        throw new Exception("overrrides: ".json_encode($overrides)." enabledPrefixes: ".json_encode($enabledPrefixes));
                }

		$systemwideEnabledVersions = self::getSystemwideEnabledVersions();

		$enabledVersions = array();
		foreach(array_keys($enabledPrefixes) as $prefix){
			$version = @$systemwideEnabledVersions[$prefix];

			// Check the version to make sure the module is not systemwide disabled.
			if(isset($version)){
				$enabledVersions[$prefix] = $version;
			}
		}

		return $enabledVersions;
	}

	private static function shouldExcludeModule($prefix)
	{
		$isTestPrefix = strpos($prefix, self::TEST_MODULE_PREFIX) === 0;
		if($isTestPrefix && !self::isTesting($prefix)){
			// This php process is not running unit tests.
			// Ignore the test prefix so it doesn't interfere with this process.
			return true;
		}

		return false;
	}

	private static function isTesting()
	{
		return PHP_SAPI == 'cli' && strpos($_SERVER['argv'][0], 'phpunit') !== FALSE;
	}

        private static function transformValueToDB($value) {
                if ($value === false) {
                        return "|false";
                } else if ($value === true) {
                        return "|true";
                } else {
                        return $value;
                }
        }

        private static function transformValueFromDB($value) {
                if ($value == "|false") {
                        return false;
                } else if ($value == "|true") {
                        return true;
                } else {
                        return $value;
                }
        }

	private static function cacheAllEnableData()
	{
		$systemwideEnabledVersions = array();
		$projectEnabledOverrides = array();
		$projectEnabledDefaults = array();

		// Only attempt to detect enabled modules if the external module tables exist.
		if(self::areTablesPresent()){
			$result = self::getSettings(null, null, array(self::KEY_VERSION, self::KEY_ENABLED));
			while($row = db_fetch_assoc($result)){
				$pid = $row['project_id'];
				$prefix = $row['directory_prefix'];
				$key = $row['key'];
				$value = self::transformValueFromDB($row['value']);

				if(self::shouldExcludeModule($prefix)){
					continue;
				}

				if($key == self::KEY_VERSION){
					$systemwideEnabledVersions[$prefix] = $value;
				}
				else if($key == self::KEY_ENABLED){
					if(isset($pid)){
						$projectEnabledOverrides[$pid][$prefix] = $value;
					}
					else if($value == 1) {
						$projectEnabledDefaults[$prefix] = true;
					}
				}
				else{
					throw new Exception("Unexpected key: $key");
				}
			}
		}

		// Overwrite any previously cached results
		self::$systemwideEnabledVersions = $systemwideEnabledVersions;
		self::$projectEnabledDefaults = $projectEnabledDefaults;
		self::$projectEnabledOverrides = $projectEnabledOverrides;
	}

	static function areTablesPresent()
	{
		$result = self::query("SHOW TABLES LIKE 'redcap_external_module%'");
		return db_num_rows($result) > 0;
	}

	static function addResource($path)
	{
		$extension = pathinfo($path, PATHINFO_EXTENSION);

		if(substr($path,0,8) == "https://") {
			$url = $path;
		}
		else {
			$path = "manager/$path";
			$fullLocalPath = __DIR__ . "/../$path";

			// Add the filemtime to the url for cache busting.
			$url = ExternalModules::$BASE_URL . $path . '?' . filemtime($fullLocalPath);
		}

		if ($extension == 'css') {
			echo "<link rel='stylesheet' type='text/css' href='" . $url . "'>";
		}
		else if ($extension == 'js') {
			echo "<script src='" . $url . "'></script>";
		}
		else {
			throw new Exception('Unsupported resource added: ' . $path);
		}
	}

	static function getLinks(){
		$pid = self::getPID();

		if(isset($pid)){
			$type = 'project';
		}
		else{
			$type = 'control-center';
		}

		$links = array();

		$versionsByPrefix = self::getEnabledModules($pid);
		foreach($versionsByPrefix as $prefix=>$version){
			$config = ExternalModules::getConfig($prefix, $version);

			foreach($config['links'][$type] as $link){
				$name = $link['name'];
				$link['url'] = self::getUrl($prefix, $link['url']);
				$links[$name] = $link;
			}
		}

		$addManageLink = function($url) use (&$links){
			$links['Manage External Modules'] = array(
				'icon' => 'brick',
				'url' => ExternalModules::$BASE_URL  . $url
			);
		};

		if(isset($pid)){
			if(SUPER_USER || !empty($modules) && self::hasDesignRights()){
				$addManageLink('manager/project.php?');
			}
		}
		else{
			$addManageLink('manager/control_center.php');
		}

		ksort($links);

		return $links;
	}

	private static function getPID()
	{
		return @$_GET['pid'];
	}

	private static function getUrl($prefix, $page)
	{
		$id = self::getIdForPrefix($prefix);
		$page = preg_replace('/\.php$/', '', $page); // remove .php extension if it exists
		return self::$BASE_URL . "?id=$id&page=$page";
	}

	static function getDisabledModuleConfigs($enabledModules)
	{
		$dirs = scandir(self::$MODULES_PATH);

		$disabledModuleVersions = array();
		foreach ($dirs as $dir) {
			if ($dir[0] == '.') {
				continue;
			}

			list($prefix, $version) = self::getParseModuleDirectoryPrefixAndVersion($dir);

			if(@$enabledModules[$prefix] != $version) {
				$versions = @$disabledModuleVersions[$prefix];
				if(!isset($versions)){
					$versions = array();

				}

				// Use array_merge_recursive() to show newest versions first.
				$disabledModuleVersions[$prefix] = array_merge_recursive(
					array($version => self::getConfig($prefix, $version)),
					$versions
				);
			}
		}

		return $disabledModuleVersions;
	}

	static function getParseModuleDirectoryPrefixAndVersion($directoryName){
		$parts = explode('_', $directoryName);

		$version = array_pop($parts);
		$prefix = implode('_', $parts);

		return array($prefix, $version);
	}

	static function getConfig($prefix, $version = null, $pid = null)
	{
		if($version == null){
			$version = self::getEnabledVersion($prefix);
		}

		$moduleDirectoryName = self::getModuleDirectoryName($prefix, $version);
		$config = @self::$configs[$moduleDirectoryName];
		if($config === null){
			$configFilePath = self::$MODULES_PATH . "$moduleDirectoryName/config.json";
			$config = json_decode(file_get_contents($configFilePath), true);

			if($config == null){
				throw new Exception("An error occurred while parsing a configuration file!  The following file is likely not valid JSON: $configFilePath");
			}

			$configs[$moduleDirectoryName] = $config;
		}

		foreach(['system-settings', 'project-settings'] as $key){
			if(!isset($config[$key])){
				$config[$key] = array();
			}
		}

		## Pull form and field list for choice list of project-settings field-list and form-list settings
		if(!empty($pid)) {
			foreach($config['project-settings'] as $configKey => $configRow) {
				if($configRow['type'] == 'field-list') {
					$choices = [];

					$sql = "SELECT field_name,element_label
							FROM redcap_metadata
							WHERE project_id = '".db_real_escape_string($pid)."'
							ORDER BY field_order";
					$result = self::query($sql);

					while($row = db_fetch_assoc($result)){
						$choices[] = ['value' => $row['field_name'],'name' => $row['field_name'] . " - " . substr($row['element_label'],0,20)];
					}

					$config['project-settings'][$configKey]['choices'] = $choices;
				}
				else if($configRow['type'] == 'form-list') {
					$choices = [];


					$sql = "SELECT DISTINCT form_name
							FROM redcap_metadata
							WHERE project_id = '".db_real_escape_string($pid)."'
							ORDER BY field_order";
					$result = self::query($sql);

					while($row = db_fetch_assoc($result)){
						$choices[] = ['value' => $row['form_name'],'name' => $row['form_name']];
					}

					$config['project-settings'][$configKey]['choices'] = $choices;
				}
			}
		}

		$config = self::addReservedSettings($config);

		return $config;
	}

	public static function getEnabledVersion($prefix)
	{
		$versionsByPrefix = self::getSystemwideEnabledVersions();
		return @$versionsByPrefix[$prefix];
	}

	private static function addReservedSettings($config)
	{
		$systemSettings = $config['system-settings'];
		$projectSettings = $config['project-settings'];

		$existingSettingKeys = array();
		foreach($systemSettings as $details){
			$existingSettingKeys[$details['key']] = true;
		}

		foreach($projectSettings as $details){
			$existingSettingKeys[$details['key']] = true;
		}

                $visibleReservedSettings = array();
                foreach(self::$RESERVED_SETTINGS as $details){
                        $key = $details['key'];
                        if(isset($existingSettingKeys[$key])){
                                throw new Exception("The '$key' setting key is reserved for internal use.  Please use a different setting key in your module.");
                        }

                        if(@$details['hidden'] != true){
                                $visibleReservedSettings[] = $details;
                        }
                }

		// Merge arrays so that reserved settings always end up at the top of the list.
		$config['system-settings'] = array_merge($visibleReservedSettings, $systemSettings);

		return $config;
	}

	static function getModuleDirectoryName($prefix, $version){
		return $prefix . '_' . $version;
	}

	static function hasProjectSettingSavePermission($moduleDirectoryPrefix, $key)
	{
		if(self::hasSystemSettingsSavePermission($moduleDirectoryPrefix)){
			return true;
		}

		if(self::hasDesignRights()){
			if(!self::isSystemSetting($moduleDirectoryPrefix, $key)){
				return true;
			}

			$level = self::getSystemSetting($moduleDirectoryPrefix, $key . self::OVERRIDE_PERMISSION_LEVEL_SUFFIX);
			return $level == self::OVERRIDE_PERMISSION_LEVEL_DESIGN_USERS;
		}

		return false;
	}

	public static function hasPermission($prefix, $version, $permissionName)
	{
		return in_array($permissionName, self::getConfig($prefix, $version)['permissions']);
	}

	static function isSystemSetting($moduleDirectoryPrefix, $key)
	{
		$version = self::getSystemSetting($moduleDirectoryPrefix, self::KEY_VERSION);
		$config = self::getConfig($moduleDirectoryPrefix, $version);

		foreach($config['system-settings'] as $details){
			if($details['key'] == $key){
				return true;
			}
		}

		return false;
	}

	static function hasDesignRights()
	{
		if(SUPER_USER){
			return true;
		}

		if(!isset($_GET['pid'])){
			// REDCap::getUserRights() will crash if no pid is set, so just return false.
			return false;
		}

		$rights = \REDCap::getUserRights();
		return $rights[USERID]['design'] == 1;
	}

	static function hasSystemSettingsSavePermission()
	{
		return self::isTesting() || SUPER_USER;
	}

	# Taken from: http://stackoverflow.com/questions/3338123/how-do-i-recursively-delete-a-directory-and-its-entire-contents-files-sub-dir
	private static function rrmdir($dir)
	{
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (is_dir($dir . "/" . $object))
						self::rrmdir($dir . "/" . $object);
					else
						unlink($dir . "/" . $object);
				}
			}
			rmdir($dir);
		}
	}

	static function getManagerJSDirectory()
	{
		return "js/";
		# just in case absolute path is needed, I have documented it here
		// return APP_PATH_WEBROOT_PARENT."/external_modules/manager/js/";
	}
}
