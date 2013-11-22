<?php

namespace LemonWeb\Deployer\Shell;

use LemonWeb\Deployer\Logger\LoggerInterface;


interface RemoteShellInterface
{
    public function __construct(LoggerInterface $logger, array $options);

    public function exec($remote_host, $command, &$output = array(), &$return = 0, $hide_pattern = '', $hide_replacement = '', $ouput_loglevel = LOG_INFO);
}
