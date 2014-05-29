<?php

namespace LemonWeb\Deployer\Other;

use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Logger\LoggerInterface;

class Gearman
{
    /**
     * @var LoggerInterface
     */
    protected $logger = null;

    /**
     * The root directory of the project
     *
     * @var string
     */
    protected $basedir = null;

    /**
     * Settings of Gearman servers including the names of the worker functions that must be restarted.
     *
     * Example:
     *        array(
     *            'servers' => array(
     *                array('ip' => '192.168.0.53', 'port' => 4730),
     *                ...
     *            ),
     *            'workers' => array(
     *                'send_email',
     *                'clear_cache',
     *                ...
     *            )
     *        )
     *
     * @var array
     */
    protected $gearman = array();

    /**
     * The path of gearman-restarter.php relative to the project root
     *
     * @var string
     */
    protected $gearman_restarter = null;

    public function __construct(LoggerInterface $logger, array $options)
    {
        $this->logger = $logger;
        $this->basedir = $options['basedir'];
        $this->gearman = $options['gearman'];

        if (null !== $options['gearman_restarter']) {
            if (!file_exists($this->basedir . '/' . $options['gearman_restarter'])) {
                throw new DeployException('Gearman restarter not found');
            }

            $this->gearman_restarter = $options['gearman_restarter'];
        }
    }

    /**
     * Gearman workers herstarten
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    public function restartWorkers($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log("restartGearmanWorkers($remote_host, $remote_dir, $target_dir)", LOG_DEBUG);

        if (!isset($this->gearman['workers']) || empty($this->gearman['workers'])) {
            return;
        }

        $cmd = "cd $remote_dir/{$target_dir}; ";

        foreach ($this->gearman['servers'] as $server) {
            foreach ($this->gearman['workers'] as $worker) {
                $worker = sprintf($worker, $this->target);

                $cmd .= "php {$this->gearman_restarter} --ip={$server['ip']} --port={$server['port']} --function=$worker; ";
            }
        }

        $output = array();
        $this->remote_shell->exec($cmd, $remote_host, $output);

        $this->logger->log($output);
    }

} 