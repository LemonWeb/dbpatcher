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
use LemonWeb\Deployer\Database\Patcher as DatabasePatcher;
use LemonWeb\Deployer\Database\SqlUpdate\Helper as DatabaseHelper;

// activate Composer's autoloader
require __DIR__ . '/../../../autoload.php';

$args = Helper::parseArgs($_SERVER['argv']);

$logger = new Logger(null, isset($args['debug']) ? (bool)$args['debug'] : false);

try {
    $usage =
        "Usage: php database-patcher.php\n" .
        "           --action=\"[update,rollback]\"\n" .
        "           --host=\"[hostname]\"\n" .
        "           --port=\"[port]\"\n" .
        "           --database=\"[database name]\"\n" .
        "           --user=\"[username]\"\n" .
        "           --pass=\"[password]\"\n" .
        "           --charset=\"[utf8,latin1]\"\n" .
        "           --timestamp=\"[DATE_RSS]\"\n" .
        "           --rootpath=\"[ROOT_PATH]\"\n" .
        "           [--register-only=1]\n" .
        "           [--files=\"[sql update filename],[sql update filename]\"]\n" .
        "           [--patches=\"[timestamp,timestamp]\"]\n".
        "           [--patch-options=\"{options}\"]\n";

    // check input
    if (
        !isset($args['action'], $args['host'], $args['port'], $args['user'], $args['pass'], $args['charset'], $args['database'], $args['timestamp'], $args['rootpath'], $args['patch-options'])
        ||
        !(isset($args['files']) || isset($args['patches']))
        ||
        !($datetime = date(DatabaseHelper::DATETIME_FORMAT, strtotime($args['timestamp'])))
    ) {
        throw new DeployException($usage, 1);
    }

    $patcher = new DatabasePatcher(
        $logger,
        $args['host'],
        $args['port'],
        $args['user'],
        $args['pass'],
        $args['charset'],
        $args['database'],
        $datetime,
        $args['rootpath'],
        unserialize($args['patch-options'])
    );

    if (isset($args['files'])) {
        $patcher->setUpdateFiles(explode(',', $args['files']));
    }

    if (isset($args['patches'])) {
        $patcher->setRevertPatches(explode(',', $args['patches']));
    }

    switch ($args['action']) {
        case Deploy::UPDATE:
            $logger->log(Deploy::UPDATE, LOG_DEBUG);
            $patcher->update(isset($args['register-only']) ? (bool)$args['register-only'] : false);
            break;
        case Deploy::ROLLBACK:
            $logger->log(Deploy::ROLLBACK, LOG_DEBUG);
            $patcher->rollback();
            break;
        default:
            throw new DeployException($usage, 1);
    }

    exit(0);
} catch (Exception $exception) {
    $logger->log($exception->getMessage());
    exit(max($exception->getCode(), 1));
}
