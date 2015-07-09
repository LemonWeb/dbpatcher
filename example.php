<?php

require __DIR__ .'/vendor/autoload.php';

use LemonWeb\DbPatcher\Application\DbPatcherApplication;

$config = array(
	'project_name' => 'project',
	'basedir' => dirname(__FILE__), // the root dir of the project
	'database_dirs' => array('data/sql-updates'),
	'database_host' => 'localhost',
	'database_port' => 3306,
	'database_name' => 'database',
	'database_user' => 'root', // if you omit these you will be asked for them if they are needed
	'database_pass' => 'p@ssw0rd',
	'database_patcher' => 'vendor/lemonweb/deployer/bin/database-patcher.php',
);

$application = new DbPatcherApplication($config);
$application->run();
