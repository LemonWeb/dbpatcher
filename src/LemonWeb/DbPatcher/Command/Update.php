<?php

namespace LemonWeb\DbPatcher\Command;

use LemonWeb\DbPatcher\Application\DbPatcherApplication;
use Bugbyte\Deployer\Deploy\Deployer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method DbPatcherApplication getApplication()
 */
class Update extends Command
{
    protected function configure()
    {
        $this
            ->setName('dbpatcher:update')
            ->setDescription('Applies the SQL patches to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $config = $this->getApplication()->getConfig();

        $deploy = new Deployer($input, $output, $config);
        $deploy->deploy();
    }
}
