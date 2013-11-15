<?php

namespace Bugbyte\Deployer\Interfaces;


interface RemoteShellInterface
{
    public function __construct(LoggerInterface $logger, $remote_user, $ssh_path);

    public function exec($remote_host, $command, &$output = array(), &$return = 0, $hide_pattern = '', $hide_replacement = '', $ouput_loglevel = LOG_DEBUG);
}
