<?php

use Bugbyte\Deployer\Exceptions\DeployException;

require dirname(__FILE__) . '/../includes/patcher_functions.php';
require dirname(__FILE__) .'/../src/Bugbyte/Deployer/Exceptions/DeployException.php';


$args = parseArgs($_SERVER['argv']);

if (!isset($args['ip'])) {
    throw new DeployException('Gearman server ip not specified');
}

if (!isset($args['port'])) {
    throw new DeployException('Gearman server port not specified');
}

if (!isset($args['function'])) {
    throw new DeployException('Gearman function name not specified');
}

// Send 'reboot' command to gearman worker.
$client = new GearmanClient();
$client->addServer($args['ip'], $args['port']);
$client->doBackground($args['function'], 'reboot');
