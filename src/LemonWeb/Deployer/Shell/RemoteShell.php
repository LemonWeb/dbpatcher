<?php

namespace LemonWeb\Deployer\Shell;

use LemonWeb\Deployer\Interfaces\LoggerInterface;
use LemonWeb\Deployer\Interfaces\RemoteShellInterface;


class RemoteShell implements RemoteShellInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger = null;

    /**
     * SSH Command path
     *
     * @var string
     */
    protected $ssh_path = null;

    /**
     * @var string
     */
    protected $remote_user = null;

    /**
     * Initialize
     *
     * @param LoggerInterface $logger
     * @param string $remote_user
     * @param string $ssh_path
     */
    public function __construct(LoggerInterface $logger, $remote_user, $ssh_path)
    {
        $this->logger = $logger;
        $this->remote_user = $remote_user;
        $this->ssh_path = $ssh_path;
    }

    /**
     * Wrapper for SSH command's
     *
     * @param string $remote_host
     * @param string $command
     * @param array $output
     * @param int $return
     * @param string $hide_pattern		Regexp to clean up output (eg. passwords)
     * @param string $hide_replacement
     * @param int $ouput_loglevel
     */
    public function exec($remote_host, $command, &$output = array(), &$return = 0, $hide_pattern = '', $hide_replacement = '', $ouput_loglevel = LOG_DEBUG)
    {
        $cmd = $this->ssh_path .' '. $this->remote_user .'@'. $remote_host .' "'. str_replace('"', '\"', $command) .'"';

        if ($hide_pattern != '') {
            $show_cmd = preg_replace($hide_pattern, $hide_replacement, $cmd);
        } else {
            $show_cmd = $cmd;
        }

        $this->logger->log('Remote: '. $show_cmd, $ouput_loglevel);

        exec($cmd, $output, $return);

        // remove some garbage returned on the first line
        array_shift($output);

        $this->logger->log(implode(PHP_EOL, $output), $ouput_loglevel);
    }
}
