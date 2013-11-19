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
    protected $remote_host = null;

    /**
     * @var string
     */
    protected $remote_user = null;

    /**
     * Initialize
     *
     * @param LoggerInterface $logger
     * @param array $options
     */
    public function __construct(LoggerInterface $logger, array $options)
    {
        $this->logger = $logger;

        $options = array_merge(array(
            'remote_host' => null,
            'remote_user' => null,
            'ssh_path' => trim(`which ssh`)
        ), $options);

        $this->remote_host = $options['remote_host'];
        $this->remote_user = $options['remote_user'];
        $this->ssh_path = $options['ssh_path'];
    }

    /**
     * Wrapper for SSH commands
     *
     * @param string $command
     * @param string $remote_host
     * @param array $output
     * @param int $return
     * @param string $hide_pattern		Regexp to clean up output (eg. passwords)
     * @param string $hide_replacement
     * @param int $ouput_loglevel
     */
    public function exec($command, $remote_host = null, &$output = array(), &$return = 0, $hide_pattern = '', $hide_replacement = '', $ouput_loglevel = LOG_DEBUG)
    {
        if (null === $remote_host) {
            $remote_host = $this->remote_host;
        }

        if ('localhost' == $remote_host) {
            $cmd = $command;
        } else {
            $cmd = $this->ssh_path .' '. $this->remote_user .'@'. $remote_host .' "'. str_replace('"', '\"', $command) .'"';
        }

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
