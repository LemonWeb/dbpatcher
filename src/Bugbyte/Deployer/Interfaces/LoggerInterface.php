<?php

namespace Bugbyte\Deployer\Interfaces;


interface LoggerInterface
{
    public function __construct($logfile = null, $debug = false);

    public function log($message, $level = LOG_INFO, $extra_newline = false);

    public function setQuiet($quiet);
}
