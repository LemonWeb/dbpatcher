<?php

use Bugbyte\Deployer\Deploy;


$deploy = new Deploy(array(
	'project_name' => 'project',
	'basedir' => dirname(__FILE__), // the root dir of the project
	'remote_host' => 'www.example.com', // can also be: array('serv1.example.com', 'serv2.example.com')
	'remote_dir' => '/home/user/project', // this is the same for all remote hosts if you define multiple
	'remote_user' => 'user', // setup public key access to make it easy for yourself, many connections are made
	'rsync_excludes' => 'config/rsync_exclude.txt',
	'target_specific_files' => array( // list of files that will be renamed on the remote host
		'web/.htaccess',
		'config/database.php'
	),
	'database_dirs' => array('data/sql-updates'),
	'database_host' => 'localhost',
	'database_port' => 3306,
	'database_name' => 'database',
	'database_user' => 'root', // if you omit these you will be asked for them if they are needed
	'database_pass' => 'p@ssw0rd',
	'target' => 'prod',
	'database_patcher'	=> 'lib/deployer/database-patcher.php',
	'datadir_patcher'	=> 'lib/deployer/datadir-patcher.php',

    // APC cache handling
    // EXPERIMENTAL: it works, but only for one user per server. Only use it yet unless you own the box.
    'apc_deploy_version_template' => 'lib/deployer/apc/deploy_version_template.php',
    'apc_deploy_version_path' => '/home/user/deploy_version.php',
    'apc_deploy_setrev_url' => 'localhost/deployer/apc/setrev.php'
));

switch($_SERVER['argv'][1])
{
	case 'deploy':
		$deploy->deploy();
		break;
	case 'rollback':
		$deploy->rollback();
		break;
	case 'cleanup':
		$deploy->cleanup();
		break;
	default:
		echo 'Usage: php example.php [deploy|rollback|cleanup]'. PHP_EOL;
}
