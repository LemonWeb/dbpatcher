<?php

namespace LemonWeb\Deployer\Filesync;

use LemonWeb\Deployer\Deploy;
use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Logger\LoggerInterface;

class Manager
{
    /**
     * @var LoggerInterface
     */
    protected $logger = null;

    /**
     * All files to be used as rsync exclude
     *
     * @var array
     */
    protected $rsync_excludes = array();

    /**
     * @param \LemonWeb\Deployer\Logger\LoggerInterface $logger
     * @param array $options
     * @throws \LemonWeb\Deployer\Exceptions\DeployException
     */
    public function __construct(LoggerInterface $logger, array $options)
    {
        $options = array_merge(array(
            // remote sync settings
        ), $options);


    }

}
