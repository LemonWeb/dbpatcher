<?php

namespace LemonWeb\Deployer\Interfaces;


interface LoggerInterface
{
    /**
     * Initialization
     *
     * @param string $logfile
     * @param bool $debug
     */
    public function __construct($logfile = null, $debug = false);

    /**
     * Output wrapper
     *
     * @param string $message
     * @param integer $level		 LOG_INFO (6)  = normal (always show),
     *								 LOG_DEBUG (7) = debugging (hidden by default)
     * @param bool $extra_newline	 Automatically add a newline at the end
     */
    public function log($message, $level = LOG_INFO, $extra_newline = false);

    /**
     * Enable/disable output suppression
     *
     * @param boolean $quiet
     * @return boolean          The previous setting
     */
    public function setQuiet($quiet);
}
