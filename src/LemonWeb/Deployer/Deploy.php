<?php

namespace LemonWeb\Deployer;

use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Database\ManagerInterface as DatabaseManagerInterface;
use LemonWeb\Deployer\Filesync\FileSyncInterface;
use LemonWeb\Deployer\Shell\LocalShellInterface;
use LemonWeb\Deployer\Logger\LoggerInterface;
use LemonWeb\Deployer\Shell\RemoteShellInterface;
use LemonWeb\Deployer\Shell\LocalShell;
use LemonWeb\Deployer\Shell\RemoteShell;
use LemonWeb\Deployer\Database\Manager as DatabaseManager;
use LemonWeb\Deployer\Filesync\Manager as FileSyncManager;
use LemonWeb\Deployer\Logger\Logger;

/**
 * The deployer
 */
class Deploy
{
    /**
     * The two states of deployment: update & rollback.
     */
    const UPDATE = 'update';
    const ROLLBACK = 'rollback';

    /**
     * @var LoggerInterface
     */
    protected $logger = null;

    /**
     * The username of the account on the remote server
     *
     * @var string
     */
    protected $remote_user = null;

    /**
     * @var LocalShellInterface
     */
    protected $local_shell = null;

    /**
     * @var RemoteShellInterface
     */
    protected $remote_shell = null;

    /**
     * @var DatabaseManagerInterface
     */
    protected $database_manager = null;

    /**
     * @var FileSyncInterface
     */
    protected $filesync_manager = null;

    /**
     * The codename of the application
     *
     * @var string
     */
    protected $project_name = null;

    /**
     * The root directory of the project
     *
     * @var string
     */
    protected $basedir = null;

    /**
     * The general timestamp of this deployment
     *
     * @var integer
     */
    protected $timestamp = null;

    /**
     * The path of gearman-restarter.php relative to the project root
     *
     * @var string
     */
    protected $gearman_restarter = null;

    /**
     * Retrieve past deployment timestamps at instantiation
     *
     * @var boolean
     */
    protected $auto_init = true;

    /**
     * Settings of Gearman servers including the names of the worker functions that must be restarted.
     *
     * Example:
     *        array(
     *            'servers' => array(
     *                array('ip' => '192.168.0.53', 'port' => 4730),
     *              ...
     *            ),
     *            'workers' => array(
     *                'send_email',
     *                'clear_cache'
     *            )
     *        )
     *
     * @var array
     */
    protected $gearman = array();

    /**
     * The project path where the deploy_timestamp.php template is located
     *
     * @var string
     */
    protected $apc_deploy_timestamp_template = null;

    /**
     * The absolute physical path where the deploy_timestamp.php should be placed (on the remote server)
     *
     * @var string
     */
    protected $apc_deploy_timestamp_path = null;

    /**
     * The local url (on the remote server) where setrev.php can be reached (one for each remote host)
     *
     * @var string|array
     */
    protected $apc_deploy_setrev_url = null;

    /**
     * Initializes the deployment system.
     *
     * @param array $options
     * @throws DeployException
     *
     * TODO: make the dependencies (logger, shells, databasemanager) configurable
     */
    public function __construct(array $options)
    {
        // mandatory setting
        $this->basedir = $options['basedir'];
        $this->remote_user = $options['remote_user'];

        // merge options with defaults
        $options = array_merge(array(
            'debug' => false,
            'auto_init' => true,
            'logfile' => null
        ), $options);

        $this->remote_dir = $options['remote_dir'];

        // initialize logger
        $this->logger = new Logger($options['logfile'], $options['debug']);

        // initialize local shell
        $this->local_shell = new LocalShell();

        // initialize remote shell
        $this->remote_shell = new RemoteShell($this->logger, $options);

        // initialize remote sync manager
        if (null !== $options['remote_dir']) {
            $this->filesync_manager = new FileSyncManager(
                $this->logger,
                $options
            );

            $this->gearman = $options['gearman'];

            if (null !== $options['gearman_restarter']) {
                if (!file_exists($this->basedir . '/' . $options['gearman_restarter'])) {
                    throw new DeployException('Gearman restarter not found');
                }

                $this->gearman_restarter = $options['gearman_restarter'];
            }

            if (isset($options['apc_deploy_version_template']) && isset($options['apc_deploy_version_path']) && isset($options['apc_deploy_setrev_url'])) {
                $this->apc_deploy_version_template = $options['apc_deploy_version_template'];
                $this->apc_deploy_version_path = $options['apc_deploy_version_path'];

                if (!(
                        is_string($options['remote_host']) &&
                        is_string($options['apc_deploy_setrev_url'])
                    ) &&
                    !(
                        is_array($options['remote_host']) &&
                        is_array($options['apc_deploy_setrev_url']) &&
                        count($options['remote_host']) == count($options['apc_deploy_setrev_url'])
                    )
                ) {
                    throw new DeployException('apc_deploy_setrev_url must be similar to remote_host (string or array with the same number of elements)');
                }

                $this->apc_deploy_setrev_url = $options['apc_deploy_setrev_url'];
            }
        }

        // initialize database manager
        if (null !== $options['database_dirs']) {
            if (!isset($options['control_host'])) {
                $options['control_host'] = is_array($options['remote_host']) ? $options['remote_host'] : $options['remote_host'];
            }

            // instantiate a separate remote shell for the database manager that uses control_host as the default remote host
            $remote_shell = new RemoteShell($this->logger, $options);

            $this->database_manager = new DatabaseManager(
                $this->logger,
                $this->local_shell,
                $remote_shell,
                $options
            );

            if (!$this->database_manager instanceof DatabaseManagerInterface) {
                throw new DeployException('Object of type ' . get_class($this->database_manager) . ' does not implement DatabaseManagerInterface', 1);
            }

            $this->database_manager->setDirs($options['database_dirs']);

            if (null !== $options['database_patcher']) {
                if (!file_exists($this->basedir . '/' . $options['database_patcher'])) {
                    throw new DeployException('Database patcher not found');
                }

                $this->database_manager->setPatcher($options['database_patcher']);
            }

            // als database host niet wordt meegegeven automatisch de eerste remote host pakken.
            $this->database_manager->setHost(
                isset($options['database_host']) ? $options['database_host'] : $options['control_host'],
                $options['database_port']
            );

            $this->database_manager->setDatabaseName($options['database_name']);
            $this->database_manager->setUsername($options['database_user']);
            $this->database_manager->setPassword($options['database_pass']);
        }

        $this->auto_init = $options['auto_init'];

        if (!$this->auto_init) {
            return;
        }

        $this->initialize();
    }

    /**
     * Determines the timestamp of the new deployment and those of the latest two
     */
    protected function initialize()
    {
        $this->logger->log('initialize', LOG_DEBUG);

        $this->timestamp = time();

        if ($this->filesync_manager) {
            $this->filesync_manager->initialize($this->timestamp);
        }

        if ($this->database_manager) {
            $this->database_manager->initialize($this->timestamp);
        }
    }

    /**
     *
     *
     * @param string $action update or rollback
     * @throws DeployException
     * @return bool                 if the user wants to proceed with the deployment
     */
    protected function check($action)
    {
        $this->logger->log('check', LOG_DEBUG);

        if ($this->filesync_manager) {
            $this->filesync_manager->check($action);

            if (isset($this->apc_deploy_version_template)) {
                if (!file_exists($this->apc_deploy_version_template)) {
                    throw new DeployException("{$this->apc_deploy_version_template} does not exist.");
                }
            }
        }

        if ($this->database_manager) {
            $this->database_manager->check($action);
        }

        // als alles goed is gegaan kan er doorgegaan worden met de deployment
        if (self::UPDATE == $action) {
            return $this->local_shell->inputPrompt('Proceed with deployment? (y/n) [n]: ', 'n', false, array('y', 'n')) == 'y';
        }

        if (self::ROLLBACK == $action) {
            return $this->local_shell->inputPrompt('Proceed with rollback? (y/n) [n]: ', 'n', false, array('y', 'n')) == 'y';
        }

        throw new DeployException("Action must be 'update' or 'rollback'.");
    }

    /**
     * Uploads the project and executes database updates.
     */
    public function deploy()
    {
        $this->logger->log('deploy', LOG_DEBUG);

        if (!$this->check(self::UPDATE)) {
            return;
        }

        if ($this->filesync_manager) {
            $this->filesync_manager->createDeployment();
        }

        // after uploads are completed, runs database changes
        if ($this->database_manager) {
            $this->database_manager->update($this->remote_dir, $this->remote_target_dir);
        }

        if ($this->filesync_manager) {
            $this->filesync_manager->activateDeployment();
        }

        // check for obsolete deployments
        $this->cleanup();
    }

    /**
     * Reverts the last deployment.
     */
    public function rollback()
    {
        $this->logger->log('rollback', LOG_DEBUG);

        if (!$this->previous_remote_target_dir) {
            $this->logger->log('Rollback impossible, no previous deployment found !');

            return;
        }

        if (!$this->check(self::ROLLBACK)) {
            return;
        }

        if ($this->filesync_manager) {
            $this->filesync_manager->deactivateDeployment();
        }

        // Revert the database (mind that the current version does that, not the previous)
        if ($this->database_manager) {
            $this->database_manager->rollback($this->remote_dir, $this->last_remote_target_dir);
        }

        if ($this->filesync_manager) {
            $this->filesync_manager->deleteDeployment();
        }
    }

    /**
     * Deletes old, obsolete deployments
     */
    public function cleanup()
    {
        $this->logger->log('cleanup', LOG_DEBUG);

        if ($this->filesync_manager) {
            $this->filesync_manager->cleanup();
        }
    }

    /**
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function clearRemoteCaches($remote_host, $remote_dir, $target_dir)
    {
        if (!isset($this->apc_deploy_version_template, $this->apc_deploy_version_path, $this->apc_deploy_setrev_url) ||
            !($this->apc_deploy_version_template && $this->apc_deploy_version_path && $this->apc_deploy_setrev_url)
        ) {
            return;
        }

        // find the apc_deploy_setrev_url that belongs to this remote_host
        $apc_deploy_setrev_url = is_array($this->apc_deploy_setrev_url)
            ? $this->apc_deploy_setrev_url[array_search($remote_host, $this->remote_host)]
            : $this->apc_deploy_setrev_url;

        $output = array();
        $return = null;

        $this->remote_shell->exec(
            "cd $remote_dir/$target_dir; " .
            "cat {$this->apc_deploy_version_template} | sed 's/#deployment_timestamp#/{$this->timestamp}/' > {$this->apc_deploy_version_path}.tmp; " .
            "mv {$this->apc_deploy_version_path}.tmp {$this->apc_deploy_version_path}; " .
            "curl -s -S {$apc_deploy_setrev_url}?rev={$this->timestamp}",
            $remote_host,
            $output, $return);

        $this->logger->log($output);

        if (0 != $return) {
            $this->logger->log("$remote_host: Clear cache failed");
        }
    }

    /**
     * Uploads files to the new directory on the remote server
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function updateFiles($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log('updateFiles', LOG_DEBUG);

        $this->rsyncExec(
            $this->rsync_path . ' -azcO --force --delete --progress ' . $this->prepareExcludes() . ' ' . $this->prepareLinkDest($remote_dir) . ' ./ ' .
            $this->remote_user . '@' . $remote_host . ':' . $remote_dir . '/' . $target_dir
        );

        $this->fixDatadirSymlinks($remote_host, $remote_dir, $target_dir);

        $this->renameTargetFiles($remote_host, $remote_dir);
    }

    /**
     * Executes the datadir patcher to create symlinks to the data dirs.
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function fixDatadirSymlinks($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log('fixDatadirSymlinks', LOG_DEBUG);

        if (empty($this->data_dirs)) {
            return;
        }

        $this->logger->log('Creating data dir symlinks:', LOG_DEBUG);

        $cmd =
            "cd $remote_dir/{$target_dir}; " .
            "php {$this->datadir_patcher} --datadir-prefix={$this->data_dir_prefix} --previous-dir={$this->last_remote_target_dir} " . implode(' ', $this->data_dirs);

        $output = array();
        $this->remote_shell->exec($cmd, $remote_host, $output);

        $this->logger->log($output);
    }

    /**
     * Gearman workers herstarten
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function restartGearmanWorkers($remote_host, $remote_dir, $target_dir)
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
        $return = null;

        $this->remote_shell->exec($cmd, $remote_host);
    }

    /**
     * Update de production-symlink naar de nieuwe (of oude, bij rollback) upload directory
     */
    protected function changeSymlink($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log('changeSymlink', LOG_DEBUG);

        $output = array();
        $return = null;
        $this->remote_shell->exec("cd $remote_dir; rm production; ln -s {$target_dir} production", $remote_host, $output, $return);
    }

    /**
     * @param string $remote_host
     * @param string $remote_dir
     */
    protected function renameTargetFiles($remote_host, $remote_dir)
    {
        if ($files_to_move = $this->listFilesToRename($remote_host, $remote_dir)) {
            // configfiles verplaatsen
            $target_files_to_move = '';

            foreach ($files_to_move as $newpath => $currentpath) {
                $target_files_to_move .= "mv $currentpath $newpath; ";
            }

            $this->remote_shell->exec("cd {$remote_dir}/{$this->remote_target_dir}; $target_files_to_move", $remote_host);
        }
    }

    /**
     * Stub method for code to be run *before* deployment
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function preDeploy($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log("preDeploy($remote_host, $remote_dir, $target_dir)", LOG_DEBUG);
    }

    /**
     * Stub method for code to be run *after* deployment and *before* the cache clears
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function postDeploy($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log("postDeploy($remote_host, $remote_dir, $target_dir)", LOG_DEBUG);

        $this->restartGearmanWorkers($remote_host, $remote_dir, $target_dir);
    }

    /**
     * Stub methode voor extra uitbreidingen die *voor* rollback worden uitgevoerd
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function preRollback($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log("preRollback($remote_host, $remote_dir, $target_dir)", LOG_DEBUG);
    }

    /**
     * Stub methode voor extra uitbreidingen die *na* rollback worden uitgevoerd
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function postRollback($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log("postRollback($remote_host, $remote_dir, $target_dir)", LOG_DEBUG);

        $this->restartGearmanWorkers($remote_host, $remote_dir, $target_dir);
    }
}
