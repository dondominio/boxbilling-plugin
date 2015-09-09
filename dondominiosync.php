<?php

/**
 * DonDominio Domain Importer for WHMCS
 * Synchronization tool for domains in DonDomino accounts and WHMCS.
 * @copyright Soluciones Corporativas IP, SL 2015
 * @package DonDominioWHMCSImporter
 */
 
/**
 * Application version.
 */
define('APP_VERSION', '0.2');

/**#@+
 * Required files.
 */
$config = require dirname(__FILE__) . '/../../../bb-config.php';			//BoxBilling config
require dirname(__FILE__) . '/lib/class/Log.php';			//Log Handlerls ..
require dirname(__FILE__) . '/lib/class/Output.php';		//Output Handler
require dirname(__FILE__) . '/lib/class/DDSync.php';		//Sync library
require dirname(__FILE__) . '/lib/class/Arguments.php';		//Argument parser
require dirname(__FILE__) . '/lib/sdk/DonDominioAPI.php';	//DD API SDK
/**#@-*/

/*
 * Connecting to the database.
 */
$dsn = $config['db']['type'] . ':dbname=' . $config['db']['name'] . ';host=' . $config['db']['host'] . ';charset=UTF8';

try{
	$dbi = new PDO($dsn, $config['db']['user'], $config['db']['password']);
}catch(PDOException $e){
	die("Connection to database failed: " . $e->getMessage());
}

/*
* Arguments passed to the application.
*/
$arguments = new Arguments;

$arguments->addOption(array('username', 'u'), null, 'DonDominio API Username (Required)');
$arguments->addOption(array('password', 'p'), null, 'DonDominio API Password (Required)');
$arguments->addOption('uid', null, 'Default Client Id (Required)');
$arguments->addOption(array('output', 'o'), "php://stdout", 'Filename to output data - Defaults to STDOUT');

$arguments->addFlag('forceUID', 'Use the default Client Id for all domains');
$arguments->addFlag('sync', 'Only update existing domains; don\'t create missing ones');
$arguments->addFlag('dry', 'Do not make any changes to the database');
$arguments->addFlag(array('verbose', 'v'), 'Display extra output');
$arguments->addFlag(array('debug', 'd'), 'Display cURL debug information');
$arguments->addFlag(array('silent', 's'), 'No output');
$arguments->addFlag('version', 'Version information');
$arguments->addFlag(array('help', 'h'), 'This information');

$arguments->parse();
/*
* --
*/

//¿Enable Silent mode?
if($arguments->get('silent')){
	Output::setSilent(true);
}

//Set output file/method
Output::setOutput($arguments->get('output'));

//Check required arguments
//If an argument is missing, show help screen.
//Also show help screen with --help (-h) flag.
if(
	(
		!$arguments->get('username') ||
		!$arguments->get('password') ||
		!$arguments->get('uid') ||
		$arguments->get('help')
	) &&
	!$arguments->get('version')
){
	$arguments->helpScreen();
	
	Output::line("");
	
	exit();
}

//Display version information
if($arguments->get('version')){
	Output::debug("Version information requested");
	
	displayVersion();
	
	exit();
}

//¿Is the "verbose" flag set?
//If so, enable verbose mode
if($arguments->get('verbose')){
	Output::setDebug(true);
}

/*
 * Init DD API SDK
 */
Output::debug("Initializing DonDominio API Client");

//Options for DD API SDK
$options = array(
	'endpoint' => 'https://simple-api-test.dondominio.net',
	'port' => 443,
	'apiuser' => $arguments->get('username'),
	'apipasswd' => $arguments->get('password'),
	'autoValidate' => true,
	'versionCheck' => true,
	'debug' => ($arguments->get('debug') && !$arguments->get('silent')) ? true : false,
	'response' => array(
		'throwExceptions' => true
	)
);

//The DonDominio API Client
$dondominio = new DonDominioAPI($options);

/*
 * Start sinchronization.
 */
Output::debug("Initializing Sync");

//DDSync class
$sync = new DDSync(array(
	'apiClient' => $dondominio,						//An initialized DonDominioAPI object
	'clientId' => $arguments->get('uid'),			//Default WHMCS client ID
	'dryrun' => $arguments->get('dry'),				//Dry run - makes no changes to database
	'forceClientId' => $arguments->get('forceUID')	//Always use default WHMCS Client ID for all operations
));

//Start syncing
$sync->sync();

/**
 * Display version information.
 */
function displayVersion()
{
	Output::line("");
	Output::line("DonDominio Domain Importer for BoxBilling v" . APP_VERSION);
	Output::line("Copyright (c) 2015 Soluciones Corporativas IP SL");
	Output::line("");
	Output::line("For usage instructions, use -h or --help");
	Output::line("");
}

/**
 * Make a query to the database.
 */
function full_query($sql)
{
	global $dbi;
	
	$statement = $dbi->prepare($sql);
	
	$statement->execute();
	
	$error = $statement->errorInfo();
	
	if(!empty($error[2])){
		output::debug($error[2]);
		return array();
	}
	
	return $statement->fetchAll();
}