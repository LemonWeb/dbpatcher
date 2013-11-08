<?php

/**
 * Execute database updates and rollbacks.
 *
 * @see Bugbyte\Deployer\Database\Manager::sendToDatabase()
 */

use Bugbyte\Deployer\Deploy;
use Bugbyte\Deployer\Exceptions\DeployException;
use Bugbyte\Deployer\Logger\Logger as Logger;
use Bugbyte\Deployer\Patchers\Helper;
use Bugbyte\Deployer\Patchers\Database as DatabasePatcher;
use Bugbyte\Deployer\Database\Helper as DatabaseHelper;

require __DIR__ .'/../../../autoload.php';

$args = Helper::parseArgs($_SERVER['argv']);

var_dump((bool) $args['debug']);

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
                    ' --timestamp="[Y-m-d H:i:s]"'.
                    ' < --files="[sql update filename],[sql update filename]" > '.
                    ' < --patches="[timestamp,timestamp]"] >';

    // check input
    if (
        !isset($args['action'], $args['host'], $args['port'], $args['user'], $args['pass'], $args['database'], $args['timestamp'])
        ||
        !(isset($args['files']) || isset($args['patches']))
        ||
        !($datetime = date(DatabaseHelper::DATETIME_FORMAT, $args['timestamp']))
    ) {
        throw new DeployException($usage, 1);
    }

    var_dump($args['timestamp'], $datetime);

    $rootpath = Helper::findRootPath($_SERVER['argv'][0], __FILE__);

    $patcher = new DatabasePatcher($logger, $args['host'], $args['port'], $args['user'], $args['pass'], $args['database'], $datetime, $rootpath);

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