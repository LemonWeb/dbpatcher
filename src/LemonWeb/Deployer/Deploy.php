<?php

namespace LemonWeb\Deployer;

use LemonWeb\Deployer\Other\APC;
use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Database\ManagerInterface as DatabaseManagerInterface;
use LemonWeb\Deployer\Other\Gearman;
use LemonWeb\Deployer\Shell\LocalShellInterface;
use LemonWeb\Deployer\Logger\LoggerInterface;
use LemonWeb\Deployer\Shell\RemoteShellInterface;
use LemonWeb\Deployer\Shell\LocalShell;
use LemonWeb\Deployer\Shell\RemoteShell;
use LemonWeb\Deployer\Database\Manager as DatabaseManager;
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
     * @var Other\Gearman
     */
    protected $gearman_handler = null;

    /**
     * @var Other\APC
     */
    protected $apc_handler = null;

    /**
     * Formatting of the deployment directories
     *
     * @var string
     */
    protected $remote_dir_format = '%project_name%_%timestamp%';

    /**
     * Date format in the name of the deployment directories
     * (format parameter of date())
     *
     * @var string
     */
    protected $remote_dir_timestamp_format = 'Y-m-d_His';

    /**
     * The directory of this project on the remote server
     *
     * @var string
     */
    protected $remote_dir = null;

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
     * The hostname(s) of the remote server(s)
     *
     * @var string|array
     */
    protected $remote_host = null;

    /**
     * The directory where the new deployment will go
     *
     * @var string
     */
    protected $remote_target_dir = null;

    /**
     * The timestamp of the previous deployment
     *
     * @var integer
     */
    protected $previous_timestamp = null;

    /**
     * The timestamp of the latest deployment
     *
     * @var integer
     */
    protected $last_timestamp = null;

    /**
     * The directory of the previous deployment
     *
     * @var string
     */
    protected $previous_remote_target_dir = null;

    /**
     * The directory of the latest deployment
     *
     * @var string
     */
    protected $last_remote_target_dir = null;

    /**
     * Target environment (stage, prod, etc.)
     *
     * @var string
     */
    protected $target = null;

    /**
     * The path of datadir-patcher.php relative to the project root
     *
     * @var string
     */
    protected $datadir_patcher = null;

    /**
     * Directories containing User Generated Content
     *
     * @var array
     */
    protected $data_dirs = null;

    /**
     * The name of the directory that will contain all data_dirs
     *
     * @var string
     */
    protected $data_dir_prefix = 'data';

    /**
     * Retrieve past deployment timestamps at instantiation
     *
     * @var boolean
     */
    protected $auto_init = true;

    /**
     * Files to be renamed depending on the target environment.
     *
     * Example:
     * 		'config/databases.yml'
     *
     * On deployment to stage the stage-specific file is renamed:
     * 		config/databases.stage.yml -> config/databases.yml
     *
     * On deployment to prod:
     * 		config/databases.prod.yml => config/databases.yml
     *
     * The files-to-be-renamed will be checked for existence before deployment continues.
     *
     * @var array
     */
    protected $target_specific_files = array();

    /**
     * Cache for listFilesToRename()
     *
     * @var array
     */
    protected $files_to_rename = array();

    /**
     * Command paths
     */
    protected $rsync_path = 'rsync';

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
        // required settings
        $this->basedir = $options['basedir'];
        $this->remote_user = $options['remote_user'];

        // merge options with defaults
        $options = array_merge(array(
            'debug' => false,
            'auto_init' => true,
            'logfile' => null,

            // remote sync settings
            'remote_dir' => null,
            'project_name' => null,
            'remote_host' => null,
            'target' => null,
            'target_specific_files' => array(),
            'rsync_path' => trim(`which rsync`),
            'rsync_excludes' => array(),
            'data_dirs' => array(),
            'datadir_patcher' => null,
            'gearman' => null,
            'gearman_restarter' => null,

            'database_dirs' => null,
        ), $options);

        // initialize logger
        $this->logger = new Logger($options['logfile'], $options['debug']);

        // initialize local shell
        $this->local_shell = new LocalShell();

        // initialize remote shell
        $this->remote_shell = new RemoteShell($this->logger, $options);

        // initialize remote sync manager
        if (null !== $options['remote_dir']) {
            $this->remote_dir = $options['remote_dir'] .'/'. $this->target;
            $this->project_name = $options['project_name'];
            $this->remote_host = $options['remote_host'];
            $this->remote_user = $options['remote_user'];
            $this->target = $options['target'];

            if (isset($options['target_specific_files'])) {
                $this->target_specific_files = (array) $options['target_specific_files'];
            }

            $this->rsync_path = $options['rsync_path'];
            $this->rsync_excludes = (array) $options['rsync_excludes'];
            $this->data_dirs = (array) $options['data_dirs'];

            if (null !== $options['datadir_patcher']) {
                if (!file_exists($this->basedir .'/'. $options['datadir_patcher'])) {
                    throw new DeployException('Datadir patcher not found');
                }

                $this->datadir_patcher = $options['datadir_patcher'];
            }

            if (isset($options['gearman'], $options['gearman_restarter'])) {
                $this->gearman_handler = new Gearman($this->logger, $options);
            }

            if (isset($options['apc_deploy_version_template'], $options['apc_deploy_version_path'], $options['apc_deploy_setrev_url'])) {
                $this->apc_handler = new APC($options);
            }
        }

        // initialize database manager
        if (null !== $options['database_dirs']) {
            if (!isset($options['control_host'])) {
                $options['control_host'] = is_array($options['remote_host']) ? $options['remote_host'] : $options['remote_host'];
            }

            // instantiate a separate remote shell for the database manager that uses control_host as the default remote host
            $remote_shell = new RemoteShell($this->logger, $options);

            $database_manager = new DatabaseManager(
                $this->logger,
                $this->local_shell,
                $remote_shell,
                $options
            );

            if (!$database_manager instanceof DatabaseManagerInterface) {
                throw new DeployException('Object of type ' . get_class($database_manager) . ' does not implement DatabaseManagerInterface', 1);
            }

            if (null !== $options['database_patcher']) {
                if (!file_exists($this->basedir . '/' . $options['database_patcher'])) {
                    throw new DeployException('Database patcher not found');
                }

                $database_manager->setPatcher($options['database_patcher']);
            }

            // als database host niet wordt meegegeven automatisch de eerste remote host pakken.
            $database_manager->setHost(
                isset($options['database_host']) ? $options['database_host'] : $options['control_host'],
                $options['database_port']
            );

            $database_manager->setDatabaseName($options['database_name']);
            $database_manager->setUsername($options['database_user']);
            $database_manager->setPassword($options['database_pass']);

            $this->database_manager = $database_manager;
        }

        $this->auto_init = $options['auto_init'];

        if ($this->auto_init) {
            $this->initialize();
        }
    }

    /**
     * Determines the timestamp of the new deployment and those of the latest two
     */
    protected function initialize()
    {
        $this->logger->log('initialize', LOG_DEBUG);

        $this->timestamp = time();

        if (null !== $this->remote_dir) {
            // in case of multiple remote hosts use the first
            $remote_host = is_array($this->remote_host) ? $this->remote_host[0] : $this->remote_host;

            list($this->previous_timestamp, $this->last_timestamp) = $this->findPastDeploymentTimestamps($remote_host, $this->remote_dir);

            $this->remote_target_dir = strtr($this->remote_dir_format, array(
                '%project_name%' => $this->project_name,
                '%timestamp%' => date($this->remote_dir_timestamp_format, $this->timestamp)
            ));

            if ($this->previous_timestamp) {
                $this->previous_remote_target_dir = strtr($this->remote_dir_format, array(
                    '%project_name%' => $this->project_name,
                    '%timestamp%' => date($this->remote_dir_timestamp_format, $this->previous_timestamp)
                ));
            }

            if ($this->last_timestamp) {
                $this->last_remote_target_dir = strtr($this->remote_dir_format, array(
                    '%project_name%' => $this->project_name,
                    '%timestamp%' => date($this->remote_dir_timestamp_format, $this->last_timestamp)
                ));
            }
        }

        if ($this->database_manager) {
            $this->database_manager->initialize($this->timestamp, $this->previous_timestamp ?: null, $this->last_timestamp ?: null);
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

        if (is_array($this->remote_host)) {
            foreach ($this->remote_host as $key => $remote_host) {
                if ($key == 0) {
                    continue;
                }

                $this->prepareRemoteDirectory($remote_host, $this->remote_dir);
            }
        }

        if (self::UPDATE == $action) {
            $this->checkFiles(is_array($this->remote_host) ? $this->remote_host[0] : $this->remote_host, $this->remote_dir, $this->last_remote_target_dir);
        }

        if ($this->database_manager) {
            $this->database_manager->check($action);
        }

        if ($this->apc_handler) {
            $this->apc_handler->check($action);
        }

        if (self::UPDATE == $action) {
            if (is_array($this->remote_host)) {
                foreach ($this->remote_host as $remote_host) {
                    if (!$files = $this->listFilesToRename($remote_host, $this->remote_dir)) {
                        continue;
                    }

                    $this->logger->log("Target-specific file renames on $remote_host:");

                    foreach ($files as $filepath => $newpath) {
                        $this->logger->log("  $newpath => $filepath");
                    }
                }
            } else {
                if ($files = $this->listFilesToRename($this->remote_host, $this->remote_dir)) {
                    $this->logger->log('Target-specific file renames:');

                    foreach ($files as $filepath => $newpath) {
                        $this->logger->log("  $newpath => $filepath");
                    }
                }
            }
        }

        // if everything checks out, proceed with deployment
        if (self::UPDATE == $action) {
            return $this->local_shell->inputPrompt('Proceed with deployment? (y/N): ', 'n', false, array('y', 'n')) == 'y';
        }

        if (self::ROLLBACK == $action) {
            return $this->local_shell->inputPrompt('Proceed with rollback? (y/N): ', 'n', false, array('y', 'n')) == 'y';
        }

        throw new DeployException("Action must be 'update' or 'rollback'.");
    }

    /**
     * Syncs all files to the remote servers and executes database updates.
     */
    public function deploy()
    {
        $this->logger->log('deploy', LOG_DEBUG);

        if (!$this->check(self::UPDATE)) {
            return;
        }

        if (is_array($this->remote_host)) {
            // first run preDeploy for each host, then sync all files
            foreach ($this->remote_host as $remote_host) {
                $this->preDeploy($remote_host, $this->remote_dir, $this->remote_target_dir);
                $this->updateFiles($remote_host, $this->remote_dir, $this->remote_target_dir);
            }

            // after uploads are completed, runs database changes
            if ($this->database_manager) {
                $this->database_manager->update($this->remote_dir, $this->remote_target_dir);
            }

            // activate the new deployment by updating symlinks and running postDeploy
            foreach ($this->remote_host as $remote_host) {
                $this->changeSymlink($remote_host, $this->remote_dir, $this->remote_target_dir);
                $this->postDeploy($remote_host, $this->remote_dir, $this->remote_target_dir);
                $this->clearRemoteCaches($remote_host, $this->remote_dir, $this->remote_target_dir);
            }
        } else {
            $this->preDeploy($this->remote_host, $this->remote_dir, $this->remote_target_dir);
            $this->updateFiles($this->remote_host, $this->remote_dir, $this->remote_target_dir);

            if ($this->database_manager) {
                $this->database_manager->update($this->remote_dir, $this->remote_target_dir);
            }

            $this->changeSymlink($this->remote_host, $this->remote_dir, $this->remote_target_dir);
            $this->postDeploy($this->remote_host, $this->remote_dir, $this->remote_target_dir);
            $this->clearRemoteCaches($this->remote_host, $this->remote_dir, $this->remote_target_dir);
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

        if (is_array($this->remote_host)) {
            // eerst op alle hosts de symlink terugdraaien
            foreach ($this->remote_host as $remote_host) {
                $this->preRollback($remote_host, $this->remote_dir, $this->previous_remote_target_dir);
                $this->changeSymlink($remote_host, $this->remote_dir, $this->previous_remote_target_dir);
            }

            // Revert the database (mind that the current version does that, not the previous)
            if ($this->database_manager) {
                $this->database_manager->rollback($this->remote_dir, $this->last_remote_target_dir);
            }

            // de caches resetten
            foreach ($this->remote_host as $remote_host) {
                $this->clearRemoteCaches($remote_host, $this->remote_dir, $this->previous_remote_target_dir);
                $this->postRollback($remote_host, $this->remote_dir, $this->previous_remote_target_dir);
            }

            // als laatste de nieuwe directory terugdraaien
            foreach ($this->remote_host as $remote_host) {
                $this->rollbackFiles($remote_host, $this->remote_dir, $this->last_remote_target_dir);
            }
        } else {
            $this->preRollback($this->remote_host, $this->remote_dir, $this->previous_remote_target_dir);
            $this->changeSymlink($this->remote_host, $this->remote_dir, $this->previous_remote_target_dir);

            // nadat de symlinks zijn teruggedraaid de database terugdraaien (let op dat de huidige versie dat nog moet doen)
            if ($this->database_manager) {
                $this->database_manager->rollback($this->remote_dir, $this->last_remote_target_dir);
            }

            $this->clearRemoteCaches($this->remote_host, $this->remote_dir, $this->previous_remote_target_dir);
            $this->postRollback($this->remote_host, $this->remote_dir, $this->previous_remote_target_dir);
            $this->rollbackFiles($this->remote_host, $this->remote_dir, $this->last_remote_target_dir);
        }
    }

    /**
     * Deletes old, obsolete deployments
     */
    public function cleanup()
    {
        $this->logger->log('cleanup', LOG_DEBUG);

        $past_deployments = array();

        if (is_array($this->remote_host)) {
            foreach ($this->remote_host as $remote_host) {
                if ($past_dirs = $this->collectPastDeployments($remote_host, $this->remote_dir)) {
                    $past_deployments[] = array(
                        'remote_host' => $remote_host,
                        'remote_dir' => $this->remote_dir,
                        'dirs' => $past_dirs
                    );
                }
            }
        } else {
            if ($past_dirs = $this->collectPastDeployments($this->remote_host, $this->remote_dir)) {
                $past_deployments[] = array(
                    'remote_host' => $this->remote_host,
                    'remote_dir' => $this->remote_dir,
                    'dirs' => $past_dirs
                );
            }
        }

        if (!empty($past_deployments)) {
            if ($this->local_shell->inputPrompt('Delete old directories? (y/N): ', 'n', false, array('y', 'n')) == 'y') {
                $this->deletePastDeployments($past_deployments);
            }
        } else {
            $this->logger->log('No cleanup needed');
        }
    }

    /**
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function clearRemoteCaches($remote_host, $remote_dir, $target_dir)
    {
        if ($this->apc_handler) {
            $this->apc_handler->clear($remote_host, $remote_dir, $target_dir);
        }
    }

    /**
     * Shows the file and directory changes sincs the latest deploy (rsync dry-run to the latest directory on the remote server)
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @param string $target_dir
     */
    protected function checkFiles($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log('checkFiles', LOG_DEBUG);

        if (!$target_dir) {
            $this->logger->log('No deployment history found');

            return;
        }

        $this->logger->log('Changed directories and files:', LOG_INFO, true);

        $this->rsyncExec(
            $this->rsync_path . ' -azcO --force --dry-run --delete --progress ' . $this->prepareExcludes() . ' ./ ' .
            $this->remote_user . '@' . $remote_host . ':' . $remote_dir . '/' . $this->last_remote_target_dir, 'Rsync check is mislukt'
        );
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
     * Verwijdert de laatst geuploadde directory
     */
    protected function rollbackFiles($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log('rollbackFiles', LOG_DEBUG);

        $this->remote_shell->exec('cd ' . $remote_dir . '; rm -rf ' . $target_dir, $remote_host);
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
     * Makes a list of all files specific to a target that should be remaned on the remote server
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @throws DeployException
     * @return array
     */
    protected function listFilesToRename($remote_host, $remote_dir)
    {
        if (!isset($this->files_to_rename["$remote_host-$remote_dir"])) {
            $target_files_to_move = array();

            try {
                // calculate the current new filenames (with the target name in their filename)
                if (!empty($this->target_specific_files)) {
                    foreach ($this->target_specific_files as $filepath) {
                        $ext = pathinfo($filepath, PATHINFO_EXTENSION);

                        if (isset($target_files_to_move[$filepath])) {
                            $target_filepath = str_replace(".$ext", ".{$this->target}.$ext", $target_files_to_move[$filepath]);
                        } else {
                            $target_filepath = str_replace(".$ext", ".{$this->target}.$ext", $filepath);
                        }

                        $target_files_to_move[$filepath] = $target_filepath;
                    }
                }

                // check if all files exist
                if (!empty($target_files_to_move)) {
                    foreach ($target_files_to_move as $current_filepath) {
                        if (!file_exists($current_filepath)) {
                            throw new DeployException("Target-specific file \"$current_filepath\" does not exist");
                        }
                    }
                }
            } catch (DeployException $exception) {
                echo $exception->getMessage() . PHP_EOL;
                exit;
            }

            $this->files_to_rename["$remote_host-$remote_dir"] = $target_files_to_move;
        }

        return $this->files_to_rename["$remote_host-$remote_dir"];
    }

    /**
     * Zet het array van rsync excludes om in een lijst rsync parameters
     *
     * @throws DeployException
     * @return string
     */
    protected function prepareExcludes()
    {
        $this->logger->log('prepareExcludes', LOG_DEBUG);

        chdir($this->basedir);

        $exclude_param = '';

        if (count($this->rsync_excludes) > 0) {
            foreach ($this->rsync_excludes as $exclude) {
                if (!file_exists($exclude)) {
                    throw new DeployException('Rsync exclude file not found: ' . $exclude);
                }

                $exclude_param .= '--exclude-from=' . escapeshellarg($exclude) . ' ';
            }
        }

        if (!empty($this->data_dirs)) {
            foreach ($this->data_dirs as $data_dir) {
                $exclude_param .= '--exclude ' . escapeshellarg("/$data_dir") . ' ';
            }
        }

        return $exclude_param;
    }

    /**
     * Prepares rsync's --copy-dest parameter if necessary.
     *
     * @param string $remote_dir
     * @return string
     */
    protected function prepareLinkDest($remote_dir)
    {
        $this->logger->log('prepareLinkDest', LOG_DEBUG);

        if ($remote_dir === null) {
            $remote_dir = $this->remote_dir;
        }

        $linkdest = '';

        if ($this->last_remote_target_dir) {
            $linkdest = "--copy-dest=$remote_dir/{$this->last_remote_target_dir}";
        }

        return $linkdest;
    }

    /**
     * Initializes the remote project and data directories.
     *
     * @param string $remote_host
     * @param string $remote_dir
     */
    protected function prepareRemoteDirectory($remote_host, $remote_dir)
    {
        $this->logger->log('Initialize remote directory: ' . $remote_host . ':' . $remote_dir, LOG_INFO, true);

        $output = array();
        $return = null;
        $this->remote_shell->exec("mkdir -p $remote_dir", $remote_host, $output, $return, '', '', LOG_DEBUG);

        if (empty($this->data_dirs)) {
            return;
        }

        $data_dirs = count($this->data_dirs) > 1 ? '{' . implode(',', $this->data_dirs) . '}' : implode(',', $this->data_dirs);

        $cmd = "mkdir -v -m 0775 -p $remote_dir/{$this->data_dir_prefix}/$data_dirs";

        $output = array();
        $return = null;
        $this->remote_shell->exec($cmd, $remote_host, $output, $return, '', '', LOG_DEBUG);
    }

    /**
     * Returns the timestamps of the second latest and latest deployments
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @throws DeployException
     * @return array [previous_timestamp, last_timestamp]
     */
    protected function findPastDeploymentTimestamps($remote_host, $remote_dir)
    {
        $this->logger->log('findPastDeploymentTimestamps', LOG_DEBUG);

        $this->prepareRemoteDirectory($remote_host, $remote_dir);

        if ($remote_dir === null) {
            $remote_dir = $this->remote_dir;
        }

        $dirs = array();
        $return = null;
        $this->remote_shell->exec("ls -1 $remote_dir", $remote_host, $dirs, $return, '', '', LOG_DEBUG);

        if (0 !== $return) {
            throw new DeployException('ssh initialize failed');
        }

        if (count($dirs)) {
            $past_deployments = array();
            $deployment_timestamps = array();

            foreach ($dirs as $dirname) {
                if (
                    preg_match('/' . preg_quote($this->project_name, '/') . '_(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})/', $dirname, $matches) &&
                    ($time = mktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]))
                ) {
                    $past_deployments[] = $dirname;
                    $deployment_timestamps[] = $time;
                }
            }

            $count = count($deployment_timestamps);

            if ($count > 0) {
                $this->logger->log('Past deployments:', LOG_INFO, true);
                $this->logger->log($past_deployments, LOG_INFO, true);

                sort($deployment_timestamps, SORT_NUMERIC);

                if ($count >= 2) {
                    return array_slice($deployment_timestamps, -2);
                }

                return array(null, array_pop($deployment_timestamps));
            }
        }

        return array(null, null);
    }

    /**
     * Returns all obsolete deployments that can be deleted.
     *
     * @param string $remote_host
     * @param string $remote_dir
     * @throws DeployException
     * @return array
     */
    protected function collectPastDeployments($remote_host, $remote_dir)
    {
        $this->logger->log("collectPastDeployments($remote_host, $remote_dir)", LOG_DEBUG);

        $dirs = array();
        $return = null;
        $this->remote_shell->exec("ls -1 $remote_dir", $remote_host, $dirs, $return);

        if (0 !== $return) {
            throw new DeployException('ssh initialize failed');
        }

        if (!count($dirs)) {
            return array();
        }

        $deployment_dirs = array();

        foreach ($dirs as $dirname) {
            if (preg_match('/' . preg_quote($this->project_name) . '_\d{4}-\d{2}-\d{2}_\d{6}/', $dirname)) {
                $deployment_dirs[] = $dirname;
            }
        }

        // de two latest deployments always stay
        if (count($deployment_dirs) <= 2) {
            return array();
        }

        $dirs_to_delete = array();

        sort($deployment_dirs);

        $deployment_dirs = array_slice($deployment_dirs, 0, -2);

        foreach ($deployment_dirs as $key => $dirname) {
            $time = strtotime(str_replace(array($this->project_name . '_', '_'), array('', ' '), $dirname));

            // deployments older than a month can go
            if ($time < strtotime('-1 month')) {
                $this->logger->log("$dirname is older than a month");

                $dirs_to_delete[] = $dirname;
            } elseif ($time < strtotime('-1 week')) {
                // deployments older than a week only the last one of the day stays
                if (isset($deployment_dirs[$key + 1])) {
                    $time_next = strtotime(str_replace(array($this->project_name . '_', '_'), array('', ' '), $deployment_dirs[$key + 1]));

                    // if the next deployment was on the same day this one can go
                    if (date('Y-m-d', $time_next) == date('Y-m-d', $time)) {
                        $this->logger->log("$dirname was replaced the same day");

                        $dirs_to_delete[] = $dirname;
                    } else {
                        $this->logger->log("$dirname stays");
                    }
                }
            } else {
                $this->logger->log("$dirname stays");
            }
        }

        return $dirs_to_delete;
    }

    /**
     * Deletes obsolete deployments as collected by collectPastDeployments
     *
     * @param array $past_deployments
     */
    protected function deletePastDeployments($past_deployments)
    {
        foreach ($past_deployments as $past_deployment) {
            $this->rollbackFiles($past_deployment['remote_host'], $past_deployment['remote_dir'], implode(' ', $past_deployment['dirs']));
        }
    }

    /**
     * Run rsync commands
     *
     * @param string $command
     * @param string $error_msg
     * @throws DeployException
     */
    protected function rsyncExec($command, $error_msg = 'Rsync has failed')
    {
        $this->logger->log('rsyncExec: ' . $command, LOG_DEBUG);

        chdir($this->basedir);

        passthru($command, $return);

        $this->logger->log('');

        if (0 !== $return) {
            throw new DeployException($error_msg);
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

        if ($this->gearman_handler) {
            $this->gearman_handler->restartWorkers($remote_host, $remote_dir, $target_dir);
        }
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

        if ($this->gearman_handler) {
            $this->gearman_handler->restartWorkers($remote_host, $remote_dir, $target_dir);
        }
    }
}
