<?php

/**
 * Execute database updates and rollbacks.
 *
 * @see LemonWeb\Deployer\Database\Manager::sendToDatabase()
 */

use LemonWeb\Deployer\Deploy;
use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Logger\Logger as Logger;
use LemonWeb\Deployer\Patchers\Helper;
use LemonWeb\Deployer\Patchers\Database as DatabasePatcher;
use LemonWeb\Deployer\Database\Helper as DatabaseHelper;

require __DIR__ .'/../../../autoload.php';

$args = Helper::parseArgs($_SERVER['argv']);

$logger = new Logger(null, isset($args['debug']) ? (bool) $args['debug'] : false);

try {
    $usage =
        'Usage: php database-patcher.php'.
                    ' --action="[update,rollback]"'.
                    ' --host="[hostname]"'.
                    ' --port="[port]"'.
                    ' --database="[database name]"'.
                    ' --user="[username]"'.
                    ' --pass="[password]"'.
                    ' --timestamp="[DATE_RSS]"'.
                    ' --rootpath="[ROOT_PATH]"'.
                    ' [ --files="[sql update filename],[sql update filename]" ] '.
                    ' [ --patches="[timestamp,timestamp]"] ]';

    // check input
    if (
        !isset($args['action'], $args['host'], $args['port'], $args['user'], $args['pass'], $args['database'], $args['timestamp'], $args['rootpath'])
        ||
        !(isset($args['files']) || isset($args['patches']))
        ||
        !($datetime = date(DatabaseHelper::DATETIME_FORMAT, strtotime($args['timestamp'])))
    ) {
        throw new DeployException($usage, 1);
    }

    $patcher = new DatabasePatcher($logger, $args['host'], $args['port'], $args['user'], $args['pass'], $args['database'], $datetime, $args['rootpath']);

    if (isset($args['files'])) {
        $patcher->setUpdateFiles(explode(',', $args['files']));
    }

    if (isset($args['patches'])) {
        $patcher->setRevertPatches(explode(',', $args['patches']));
    }

    switch ($args['action']) {
        case Deploy::UPDATE:
            $logger->log(Deploy::UPDATE, LOG_DEBUG);
            $patcher->update();
            break;
        case Deploy::ROLLBACK:
            $logger->log(Deploy::ROLLBACK, LOG_DEBUG);
            $patcher->rollback();
            break;
        default:
            throw new DeployException($usage, 1);
    }

    $logger->log('Done');
    exit(0);
}
catch (DeployException $e)
{
    $logger->log($e->getMessage());
    exit(max($e->getCode(), 1));
}
catch (Exception $e)
{
    $logger->log($e->getMessage());
    exit(1);
}
