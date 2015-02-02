<?php if(!defined('IN_GS')){ die('you cannot load this page directly.'); }
/**
 * Plugin Functions
 *
 * @package GetSimple
 * @subpackage Plugin-Functions
 */

/**
 * 
 * 	global array for storing all plugins greated from plugins register_plugin() call
 *	    $plugin_info[$id] = array(
 *	       'name'        => $name,
 *	       'version'     => $ver,
 *	       'author'      => $auth,
 *	       'author_url'  => $auth_url,
 *	       'description' => $desc,
 *	       'page_type'   => $type,
 *	       'load_data'   => $loaddata
 *	    );
 *
 *
 *	global array for storing action hook callbacks
 *	    $plugins[] = array(
 *	       'hook'     => hookname,
 *	       'function' => callback function name,
 *	       'priority' => priority order to execute hook,
 *	       'args'     => (array) arguments to pass to function,
 *	       'file'     => caller filename obtained from backtrace,
 *	       'line'     => caller line obtained from backtrace,
 *	    );
 *
 *
 *	global array for storing filter callbacks
 *	$filters[] = array(
 *	    'filter'   => filtername,
 *	    'function' => callback function name,
 *	    'args'     => (array) arguments for callback,
 *      'priority' => priority order to execute filter,
 *	    'active'   => (bool) is processing anti-self-looping flag
 *	);
 *
*/

/**
 * Initialize plugins data
 * create GSPLUGINSFILE if not exist
 * else load in plugins and register the inactive ones
 *
 * @since  3.4
*/
function loadPluginData(){
	GLOBAL $live_plugins;

	// Check if data\other\plugins.xml exists 
	if (!file_exists(GSDATAOTHERPATH.getDef('GSPLUGINSFILE'))){
		create_pluginsxml();
		registerInactivePlugins(get_filename_id() == 'plugins');
		return true;
	}

	read_pluginsxml();  // get the live plugins into $live_plugins array
	if(!is_frontend()) create_pluginsxml(get_filename_id() == 'plugins');  // only on backend check that plugin files have not changed, and regen
	
	registerInactivePlugins();

	if(getDef('GSPLUGINORDER',true)){
		$reorderplugins = explode(',',getDef('GSPLUGINORDER'));
		debugLog("reorder plugins".print_r($reorderplugins,true));
		$reorderplugins = array_reverse($reorderplugins);
		foreach($reorderplugins as $reorderplugin){
			$live_plugins=array($reorderplugin=>$live_plugins[$reorderplugin]) + $live_plugins; 
		}
	}

	return true;
}

/**
 * register the plugins that are not enabled
 * api checks are only done on plugins page
 *
 * @todo disabled plugins have a version of (str) 'disabled', should be 0 or null, leaving alone for now for legacy support
 *
 * @since 3.4
 * @uses $live_plugins;
 * @param  bool $apilookup lookup filename in api to get name and desc
 */
function registerInactivePlugins($apilookup = false){
	GLOBAL $live_plugins;
	// load plugins into $plugins_info

	foreach ($live_plugins as $file=>$en) {
		# debugLog("plugin: $file" . " exists: " . file_exists(GSPLUGINPATH . $file) ." enabled: " . $en); 
		if ($en!=='true' || !file_exists(GSPLUGINPATH . $file)){
			if($apilookup){
				// check api to get names of inactive plugins etc.
		 		$apiback  = get_api_details('plugin', $file);
		  		$response = json_decode($apiback);
		  		if ($response and $response->status == 'successful') {
					register_plugin( pathinfo_filename($file), $response->name, 'disabled', $response->owner, '', i18n_r('PLUGIN_DISABLED'), '', '');
		  		} else {
					register_plugin( pathinfo_filename($file), $file, 'disabled', 'Unknown', '', i18n_r('PLUGIN_DISABLED'), '', '');
		  		}

			} else {
				register_plugin( pathinfo_filename($file), $file, 'disabled', 'Unknown', '', i18n_r('PLUGIN_DISABLED'), '', '');
			}  
		}
	}
}

/**
 * change_plugin
 * 
 * Enable/Disable a plugin
 *
 * @since 2.04
 * @uses $live_plugins
 *
 * @param str  $name pluginid
 * @param bool $active default=null, sets plugin active or inactive, default=toggle
 */
function change_plugin($name,$active=null){
	global $live_plugins;

	$name = pathinfo_filename($name).'.php'; // normalize to pluginid
	if (isset($live_plugins[$name])){
		// set plugin active | inactive
		if(isset($active) and is_bool($active)) {
			$live_plugins[$name] = $active ? 'true' : 'false';
			create_pluginsxml(true);
			return;
		}

		// else we toggle
		if ($live_plugins[$name]=="true"){
			$live_plugins[$name]="false";
		} else {
			$live_plugins[$name]="true";
		}

		create_pluginsxml(true); // save change; @todo, currently reloads all files and recreates entire xml not just node, is wasteful
	}
}

/**
 * check if a plugin is active
 * determine if a plugin is active
 *
 * @since 3.4
 * @param  string $pluginid
 * @return bool   returns true if active
 */
function pluginIsActive($pluginid){
	GLOBAL $live_plugins;
	return isset($live_plugins[$pluginid.'.php']) && ($live_plugins[$pluginid.'.php'] == 'true' || $live_plugins[$pluginid.'.php'] === true);
}


/**
 * read_pluginsxml
 * 
 * Read in the plugins.xml file and populate the $live_plugins array
 *
 * @since 2.04
 * @uses $live_plugins
 * @param obj $data pass in xml data instead of using plugins.xml file load
 *
 */
function read_pluginsxml($data = null){
  	global $live_plugins;   
   
	if(!$data) $data = getXML(GSDATAOTHERPATH . getDef('GSPLUGINSFILE'));
	if($data){
   		$live_plugins= array(); // clean live_plugins
		$pluginitem = $data->item;
		if (count($pluginitem) != 0) {
			foreach ($pluginitem as $plugin) {
			  $live_plugins[trim((string)$plugin->plugin)]=trim((string)$plugin->enabled);
			}
		}

		return true;
	} 
}


/**
 * create_pluginsxml
 * 
 * Read in each plugin php file and add it to the plugins.xml file.
 * read_pluginsxml() is called to populate $live_plugins
 *
 * Does nothing if force is false and no file diff found
 * @todo  if this gets called before live plugins is loaded it will wipe your activated plugin state
 *
 * @since 2.04
 * @uses $live_plugins
 *
 * @param  bool $force force an update of plugins.xml regardless of diff check
 *
 */
function create_pluginsxml($force=false){
	GLOBAL $live_plugins;

	$pluginfiles = array();
	$success     = false;

	if (file_exists(GSPLUGINPATH)){
		$pluginfiles = getFiles(GSPLUGINPATH,'php');
	}
	else return; // plugin files path issue

	if (!$force) {
		$livekeys = array_keys($live_plugins);
		// check for file diff and use force to regen if count differs @todo better detection than just count
		if (count(array_diff($livekeys, $pluginfiles))>0 || count(array_diff($pluginfiles, $livekeys))>0) {
	  		$force = true;
		}
	}

	// create plugins.xml if missing or updating
	if ($force) {
		$xml = @new SimpleXMLExtended('<?xml version="1.0" encoding="UTF-8"?><channel></channel>');
		foreach ($pluginfiles as $fi) {
			$plugins = $xml->addChild('item');
			$p_note  = $plugins->addChild('plugin');
			$p_note->addCData($fi);
			$p_note  = $plugins->addChild('enabled');

			// check live_plugins and set enables
			if (isset($live_plugins[(string)$fi])){
				$p_note->addCData($live_plugins[(string)$fi]);
			} else {
				$p_note->addCData('false');
			}
		}

		$success = XMLsave($xml, GSDATAOTHERPATH.getDef('GSPLUGINSFILE'));
		read_pluginsxml($xml);
	}

	return $success;
}

/**
 * Create Side Menu
 *
 * This adds a side level link to a control panel's section
 *
 * @since 2.0
 * @uses $plugins
 *
 * @param string $id ID of the link you are adding
 * @param string $txt Text to add to tabbed link
 */

function createSideMenu($id, $txt, $action = null, $always = true){
	$current = false;
	if (isset($_GET['id']) && $_GET['id'] == $id && (!$action || isset($_GET[$action]))) {
		$current = true;
	}
	if ($always || $current) {
		echo '<li id="sb_'.$id.'" class="plugin_sb"><a href="load.php?id='.$id.($action ? '&amp;'.$action : '').'" '.($current ? 'class="current"' : '').' >'.$txt.'</a></li>';
	}
}

/**
 * Create Navigation Tab
 *
 * This adds a top level tab to the control panel
 *
 * @since 2.0
 * @uses $plugins
 *
 * @param string $id Id of current page
 * @param string $txt Text to add to tabbed link
 * @param string $klass class to add to a element
 */
function createNavTab($tabname, $id, $txt, $action = null) {
	global $plugin_info;
	$current = false;
	if (basename(getScriptFile()) == 'load.php') {
		$plugin_id = @$_GET['id'];
		if ($plugin_info[$plugin_id]['page_type'] == $tabname) $current = true;
	}
	echo '<li id="nav_'.$id.'" class="plugin_tab"><a href="load.php?id='.$id.($action ? '&amp;'.$action : '').'" '.($current ? 'class="current"' : '').' >'.$txt.'</a></li>';
}

/**
 * Register Plugin
 *
 * @since 2.0
 * @uses $plugin_info
 *
 * @param string $id Unique ID of your plugin 
 * @param string $name Name of the plugin
 * @param string $ver Optional, default is null. 
 * @param string $auth Optional, default is null. 
 * @param string $auth_url Optional, default is null. 
 * @param string $desc Optional, default is null.
 * @param string $type Optional, default is null. This is the page type your plugin is classifying itself
 * @param string $loaddata Optional, default is null. This is the callback funcname to run on load.php
 */
function register_plugin($id, $name, $version=null, $author=null, $author_url=null, $description=null, $type=null, $loaddata=null) {
	global $plugin_info;

	$plugin_info[$id] = array(
		'name'        => $name,
		'version'     => $version,
		'author'      => $author,
		'author_url'  => $author_url,
		'description' => $description,
		'page_type'   => $type,
		'load_data'   => $loaddata
	);
}

/**
 * adds plugin debugging info to plugin action arrays
 * add caller file and line #, normalizes to plugin origin file
 */
function addPlugindebugging(&$array){
	GLOBAl $live_plugins;

	if( !(getDef('GSDEBUGHOOKS') || isDebug()) ) return;

	$skip          = 1; // levels to this function, from add_action/add_filter
	$shift         = 3; // levels to plugin include, from common.php

	$_bt           = debug_backtrace();
	$bt            = array_slice($_bt,$skip,count($_bt)-$shift);
	$caller        = array_pop($bt); // last bactrace is the originator plugin file
	// if we ever load plugins some other way or chained, then we will have to use a loop to find it
	$pathName      = pathinfo_filename($caller['file']);
	$lineNumber    = $caller['line'];
	
	$array['file'] = $pathName.'.php';
	$array['line'] = $lineNumber;
	$array['core'] = !isset($live_plugins[$array['file']]);
}

/**
 * Add Action
 *
 * @since 2.0
 * @uses $plugins
 * @uses $pluginHooks
 *
 * @param string $hook_name
 * @param string $added_function
 * @param array $args
 * @param int $priority order of execution of hook, lower numbers execute earlier
 */
function add_action($hook_name, $added_function, $args = array(), $priority = null) {
	GLOBAL $plugins, $pluginHooks; 
	return add_hook($plugins, $pluginHooks, $hook_name, $added_function, $args, $priority);
}

/**
 * remove an action
 * @since 3.4
 * @param string $hook_name id of action
 * @param string $hook_function function to remove
 */
function remove_action($hook_name,$hook_function){
	GLOBAL $pluginHooks;
	return remove_hook($pluginHooks,$hook_name,$hook_function);
}

/**
 * Execute Action
 *
 * @since 2.0
 * @uses $plugins
 *
 * @param string $a Name of hook to execute
 */
function exec_action($a) {
	global $plugins,$pluginHooks;
 	return exec_hook($plugins, $pluginHooks, $a, 'exec_action_callback');
}

function exec_action_callback($hook){
	return call_user_func_array($hook['function'], $hook['args']);
}


/**
 * Add Filter
 *
 * @since 2.0
 * @uses $filters
 * @param str $filter_name id of filter
 * @param str $added_function callable function name
 * @param array $args arguments for $added_function
 * @param int $priority order of execution of hook, lower numbers execute earlier
 */
function add_filter($filter_name, $added_function, $args = array(), $priority = null) {
  	global $filters, $pluginFilters;
	return add_hook($filters, $pluginFilters, $filter_name, $added_function, $args, $priority);
}

/**
 * remove a filter
 * @since 3.4
 * @param string $hook_name id of action
 * @param string $hook_function function to remove
 */
function remove_filter($filter_name,$hook_function){
	GLOBAL $pluginFilters;
	return remove_hook($pluginFilters,$filter_name,$hook_function);
}

/**
 * Execute Filter
 *
 * Allows changing of the passed variable
 *
 * @since 2.0
 * @uses $filters
 *
 * @param string $script Filter name to execute
 * @param array $data
 */
function exec_filter($filter_name,$data=array()) {
	global $filters,$pluginFilters;
 	$res = exec_hook($filters, $pluginFilters, $filter_name, 'exec_filter_callback', $data, 'exec_filter_complete');
 	return ($res == null) ? $data : $res;
}

function exec_filter_callback($hook,&$data=array()){
	$data = call_user_func_array($hook['function'], array_merge(array($data),$hook['args']));
}

function exec_filter_complete($data=array()){
	return $data;
}


/**
 * Add Security Filter
 *
 * @since 3.4
 * @uses $secfilters
 * @uses $securityFilters
 * @param str $filter_name id of filter
 * @param str $added_function callable function name
 * @param array $args arguments for $added_function
 * @param int $priority order of execution of hook, lower numbers execute earlier
 */
function add_secfilter($filter_name, $added_function, $args = array(), $priority = null) {
  	global $secfilters, $securityFilters;
	return add_hook($secfilters, $securityFilters, $filter_name, $added_function, $args, $priority);  	
}


/**
 * remove a security filter
 * @since 3.4
 * @param string $filter_name id of action
 * @param string $hook_function function to remove
 */
function remove_secfilter($filter_name,$hook_function){
	GLOBAL $secFilters;
	return remove_hook($secFilters, $filter_name, $hook_function);
}

/**
 * Execute Security Filter
 *
 * Allows changing of the passed variable
 *
 * @since 2.0
 * @uses $filters
 *
 * @param string $script Filter name to execute
 * @param array $data
 */
function exec_secfilter($filter_name, $result = true) {
	global $secfilters,$securityFilters;
	
	// handle variadic
	if(func_num_args() > 1){
		$args = func_get_args();
		array_shift($args);
	} else $args = array($result);

 	$newresult = exec_hook($secfilters, $securityFilters, $filter_name, 'exec_secfilter_callback', $args, 'exec_secfilter_complete');
 	return is_bool($newresult) ? $newresult : $result;
}

function exec_secfilter_callback($hook,&$data=array()){
	$result  = &$data[0]; // last result or exec result reference

	// get num variable args	
	// copy args, and remove result
	$numargs = count($data)-1;
	$args = $data;
	array_shift($args);

	if($hook['args']) $args = array_merge(array($result),array($numargs),$args,$hook['args']);
	else $args = $data;

	// does not pass by reference, so we dont have to copy $data
	// function(currentresult,numargs,execarg,execarg,...,userarg,userarg,..)
	$newresult = call_user_func_array($hook['function'], $args);
	$result    = is_bool($newresult) ? $newresult : $result;
}

function exec_secfilter_complete($data=array()){
	return $data[0];
}


/**
 * hook helper functions
 */


/**
 * Add generic hook wrapper
 * FOR INTERNAL USE
 * @since 3.4
 * @param array $hook_array array for hooks
 * @param array $hook_hash_array array for hooks hash
 * @param string $hook_name if of hook action
 * @param string $hook_function callable function
 * @param array $args arguments to pass to $hook_function
 * @param int $priority order of execution of hook, lower numbers execute earlier
 */
function add_hook(&$hook_array, &$hook_hash_array, $hook_name, $hook_function, $args = array(), $priority = null) {
	
	if(isset($priority) && !is_int($priority)) die('priority is not NAN'); 

	if($priority === 0) $priority = 1; # fixup 0 
	clamp($priority,1,10,10); # clamp priority, min:1, max:10, default:10

	$hook = array(
		'hook'     => $hook_name,
		'function' => $hook_function,
		'args'     => (array) $args,
		'priority' => $priority
	);
	addPlugindebugging($hook); # add debug info , file, line, core
	$hook_array[] = $hook; # add to global plugins
	$hook_hash_array[$hook_name][$priority][] = &$hook_array[count($hook_array)-1]; # add ref to global plugin hook hash array
}

/**
 * remove an generic hook wrapper
 * FOR INTERNAL USE
 * @since 3.4
 * @param array  $hook_hash_array hook array
 * @param string $hook_name
 * @param string $hook_function
 */
function remove_hook(&$hook_hash_array, $hook_name, $hook_function){
	// loop priorities
	foreach($hook_hash_array[$hook_name] as $prioritykey => $hooks){
		// loop hook arrays
		foreach($hooks as $hookkey => $hook){

			// check all hooks for our function
			if($hook['function'] == $hook_function){
				
				// set hook array ref to null
				$hook_hash_array[$hook_name][$prioritykey][$hookkey] = null;
				// unset hook hash array
				unset($hook_hash_array[$hook_name][$prioritykey][$hookkey]);

				// remove priority array if empty
				if(count($hook_hash_array[$hook_name][$prioritykey]) == 0)
					unset($hook_hash_array[$hook_name][$prioritykey]);
				
				// remove hook array if empty
				if(count($hook_hash_array[$hook_name]) == 0)
					unset($hook_hash_array[$hook_name]);

				// debugLog('removing hook: '. $hook_name);
				return true;
			}
		}
	}
}


/**
 * Execute hook wrapper
 * INTERNAL USE ONLY
 * @since 3.4
 * @param array $hook_array hook array
 * @param array $hook_hash_array hook hash array
 * @param string $hook_name name of hook to execute
 */
function exec_hook(&$hook_array, &$hook_hash_array, $hook_name, $callback = '', $data = array(), $complete = '') {
	if(!$hook_array || !$hook_hash_array){
		// debugLog('hook array is empty');
		return;
	}

	if(!isset($hook_hash_array[$hook_name]) || !$hook_hash_array[$hook_name]) return;
	
	// use ref to keep subarray priority sorts, in case we wanted to reuse again, 
	// probably sorts faster when ordered also
	$hooks = &$hook_hash_array[$hook_name];
	// _debugLog($hooks);
	// if there is only one hook call it, skip sort and looping
	if(count($hooks) == 1){
		// since we do not know the priority index key
		// reset priority array to first element, then use current
		if(count(current(reset($hooks))) == 1){
			$hook = current($hooks);
			if(!isset($hook) || !isset($hook[0])) return;
			$callback($hook[0],$data);
			// if callback call it
			if(function_exists($complete)) return $complete($data);			
		}
	}

	// @todo possible optimization , no need to always sort unless hook was added
	ksort($hooks);

	foreach ($hooks as $priority){
		foreach($priority as $hook){
			if(!isset($hook)) continue;
			$callback($hook,$data);
		}
	}

	// if complete handler call it
	if(function_exists($complete)) return $complete($data);

}


/* ?> */
