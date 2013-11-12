<?php

namespace Bugbyte\Deployer\Logger;

use Bugbyte\Deployer\Interfaces\LoggerInterface as LoggerInterface;

/**
 * TODO: replace this with a PSR-3 logger
 */
class Logger implements LoggerInterface
{
    /**
     * If the deployer is run in debugging mode (more verbose output).
     *
     * @var bool
     */
    protected $debug = false;

    /**
     * The path of the logfile, if logging is required.
     *
     * @var string
     */
    protected $logfile = null;

    /**
     * A switch to (temporarily) disable output
     *
     * @var bool
     */
    protected $quiet = false;

    /**
     * Initialization
     *
     * @param string $logfile
     * @param bool $debug
     */
    public function __construct($logfile = null, $debug = false)
    {
        $this->logfile = $logfile;
        $this->debug = $debug;
    }

    /**
     * Output wrapper
     *
     * @param string $message
     * @param integer $level		 LOG_INFO (6)  = normal (always show),
     *								 LOG_DEBUG (7) = debugging (hidden by default)
     * @param bool $extra_newline	 Automatically add a newline at the end
     */
    public function log($message, $level = LOG_INFO, $extra_newline = false)
    {
        if (is_array($message)) {
            if (count($message) == 0) {
                return;
            }

            $message = implode(PHP_EOL, $message);
        }

        if (!$this->quiet && ($level == LOG_INFO || ($this->debug && $level == LOG_DEBUG))) {
            echo $message . PHP_EOL;

            if ($extra_newline) {
                echo PHP_EOL;
            }
        }

        if ($this->logfile) {
            error_log($message . PHP_EOL, 3, $this->logfile);
        }
    }

    /**
     * Enable/disable output suppression
     *
     * @param boolean $quiet
     * @return boolean          The previous setting
     */
    public function setQuiet($quiet)
    {
        $current_setting = $this->quiet;

        $this->quiet = $quiet;

        return $current_setting;
    }
}
