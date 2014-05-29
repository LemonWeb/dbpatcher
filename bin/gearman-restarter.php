<?php

use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Patchers\Helper;

require __DIR__ . '/../../../autoload.php';


$args = Helper::parseArgs($_SERVER['argv']);

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
