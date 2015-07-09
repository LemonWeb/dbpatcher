<?php

namespace LemonWeb\DbPatcher\Command;

use LemonWeb\DbPatcher\Application\DbPatcherApplication;
use Bugbyte\Deployer\Deploy\Deployer;
use LemonWeb\Deployer\Database\Patcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method DbPatcherApplication getApplication()
 */
class Patch extends Command
{
    protected function configure()
    {
        $this
            ->setName('dbpatcher:patch')
            ->setDescription('Applies the SQL patches to the database')
            ->addOption(
                'host',
                'The hostname of the database server',
                InputOption::VALUE_REQUIRED
            )
            ->addOption(
                'port',
                'The port number the database server is listening on',
                InputOption::VALUE_OPTIONAL,
                3306
            )
            ->addOption(
                'database',
                'The name of the database to patch'.
                InputOption::VALUE_REQUIRED
            )
            ->addOption(
                'username',
                'The login username to access the database with',
                InputOption::VALUE_REQUIRED
            )
            ->addOption(
                'password',
                'The login username to access the database with',
                InputOption::VALUE_REQUIRED
            )
            ->addOption(
                'charset',
                'The charset of the database connection',
                InputOption::VALUE_OPTIONAL,
                'utf8'
            )
            ->addOption(
                'timestamp',
                'The current timestamp in DATE_RSS format',
                InputOption::VALUE_OPTIONAL,
                date(DATE_RSS)
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->getApplication()->getConfig();

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
            "           [--patches=\"[timestamp,timestamp]\"]\n";

        // check input
        if (
            !isset($args['action'], $args['host'], $args['port'], $args['user'], $args['pass'], $args['charset'], $args['database'], $args['timestamp'], $args['rootpath'])
            ||
            !(isset($args['files']) || isset($args['patches']))
            ||
            !($datetime = date(DatabaseHelper::DATETIME_FORMAT, strtotime($args['timestamp'])))
        ) {
            throw new DeployException($usage, 1);
        }

        $patcher = new Patcher($logger, $args['host'], $args['port'], $args['user'], $args['pass'], $args['charset'], $args['database'], $datetime, $args['rootpath']);

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

    }
}
