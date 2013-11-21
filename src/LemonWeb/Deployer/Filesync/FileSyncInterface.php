<?php

namespace LemonWeb\Deployer\Filesync;

use LemonWeb\Deployer\Logger\LoggerInterface;

interface FileSyncInterface
{
    public function __construct(LoggerInterface $logger, array $options);

    public function initialize($timestamp);

    public function check($action);

    public function createDeployment();

    public function deleteDeployment();

    public function activateDeployment();

    public function deactivateDeployment();

    public function cleanup();
}
