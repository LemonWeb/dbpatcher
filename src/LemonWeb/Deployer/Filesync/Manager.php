<?php

namespace LemonWeb\Deployer\Filesync;

use LemonWeb\Deployer\Deploy;
use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Logger\LoggerInterface;

class Manager implements FileSyncInterface
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
     * The hostname(s) of the remote server(s)
     *
     * @var string|array
     */
    protected $remote_host = null;

    /**
     * The username of the account on the remote server
     *
     * @var string
     */
    protected $remote_user = null;

    /**
     * The directory where the new deployment will go
     *
     * @var string
     */
    protected $remote_target_dir = null;

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
     * Files to be renamed depending on the target environment.
     *
     * Example:
     *        'config/databases.yml'
     *
     * On deployment to stage the stage-specific file is renamed:
     *        config/databases.stage.yml -> config/databases.yml
     *
     * On deployment to prod:
     *        config/databases.prod.yml => config/databases.yml
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
     * All files to be used as rsync exclude
     *
     * @var array
     */
    protected $rsync_excludes = array();

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
     * @param \LemonWeb\Deployer\Logger\LoggerInterface $logger
     * @param array $options
     * @throws \LemonWeb\Deployer\Exceptions\DeployException
     */
    public function __construct(LoggerInterface $logger, array $options)
    {
        $options = array_merge(array(
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
        ), $options);

        $this->logger = $logger;
        $this->basedir = $options['basedir'];
        $this->remote_dir = $options['remote_dir'] . '/' . $options['target'];
        $this->project_name = $options['project_name'];
        $this->remote_host = $options['remote_host'];
        $this->remote_user = $options['remote_user'];
        $this->target = $options['target'];
        $this->target_specific_files = $options['target_specific_files'];

        $this->rsync_path = $options['rsync_path'];
        $this->rsync_excludes = (array)$options['rsync_excludes'];
        $this->data_dirs = (array)$options['data_dirs'];

        if (!file_exists($this->basedir . '/' . $options['datadir_patcher'])) {
            throw new DeployException('Datadir patcher not found');
        }

        $this->datadir_patcher = $options['datadir_patcher'];
    }

    /**
     * Determines the timestamp of the latest two deployments
     *
     * @param integer $timestamp
     */
    public function initialize($timestamp)
    {
        // in case of multiple remote hosts use the first
        $remote_host = is_array($this->remote_host) ? $this->remote_host[0] : $this->remote_host;

        list($this->previous_timestamp, $this->last_timestamp) = $this->findPastDeploymentTimestamps($remote_host, $this->remote_dir);

        $this->remote_target_dir = strtr($this->remote_dir_format, array(
            '%project_name%' => $this->project_name,
            '%timestamp%' => date($this->remote_dir_timestamp_format, $timestamp)
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

    /**
     * Run a dry-run to the remote server to show the changes to be made
     *
     * @param string $action
     */
    public function check($action)
    {
        if (is_array($this->remote_host)) {
            foreach ($this->remote_host as $key => $remote_host) {
                if ($key == 0) {
                    continue;
                }

                $this->prepareRemoteDirectory($remote_host, $this->remote_dir);
            }
        }

        if (Deploy::UPDATE == $action) {
            $this->checkFiles(is_array($this->remote_host) ? $this->remote_host[0] : $this->remote_host, $this->remote_dir, $this->last_remote_target_dir);
        }

        $this->checkTargetSpecificFiles($action);
    }

    /**
     * Syncs all files to the remote servers.
     */
    public function createDeployment()
    {
        if (is_array($this->remote_host)) {
            foreach ($this->remote_host as $remote_host) {
                $this->preDeploy($remote_host, $this->remote_dir, $this->remote_target_dir);
                $this->updateFiles($remote_host, $this->remote_dir, $this->remote_target_dir);
            }
        } else {
            $this->preDeploy($this->remote_host, $this->remote_dir, $this->remote_target_dir);
            $this->updateFiles($this->remote_host, $this->remote_dir, $this->remote_target_dir);
        }
    }

    /**
     * Activates the new deployment by updating symlinks and running postDeploy.
     */
    public function activateDeployment()
    {
        if (is_array($this->remote_host)) {
            foreach ($this->remote_host as $remote_host) {
                $this->changeSymlink($remote_host, $this->remote_dir, $this->remote_target_dir);
                $this->postDeploy($remote_host, $this->remote_dir, $this->remote_target_dir);
                $this->clearRemoteCaches($remote_host, $this->remote_dir, $this->remote_target_dir);
            }
        } else {
            $this->changeSymlink($this->remote_host, $this->remote_dir, $this->remote_target_dir);
            $this->postDeploy($this->remote_host, $this->remote_dir, $this->remote_target_dir);
            $this->clearRemoteCaches($this->remote_host, $this->remote_dir, $this->remote_target_dir);
        }
    }

    /**
     * Deactivates the latest deployment by reverting symlinks back to the previous one.
     */
    public function deactivateDeployment()
    {
        if (is_array($this->remote_host)) {
            // eerst op alle hosts de symlink terugdraaien
            foreach ($this->remote_host as $remote_host) {
                $this->preRollback($remote_host, $this->remote_dir, $this->previous_remote_target_dir);
                $this->changeSymlink($remote_host, $this->remote_dir, $this->previous_remote_target_dir);
            }
        } else {
            $this->preRollback($this->remote_host, $this->remote_dir, $this->previous_remote_target_dir);
            $this->changeSymlink($this->remote_host, $this->remote_dir, $this->previous_remote_target_dir);
        }
    }

    /**
     * Clears the caches on the remote servers, then deletes the latest deployment.
     */
    public function deleteDeployment()
    {
        if (is_array($this->remote_host)) {
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
            if ($this->local_shell->inputPrompt('Delete old directories? (y/n) [n]: ', 'n', false, array('y', 'n')) == 'y') {
                $this->deletePastDeployments($past_deployments);
            }
        } else {
            $this->logger->log('No cleanup needed');
        }
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
                // of deployments older than a week only the last one of the day stays
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
     * Verwijdert de laatst geuploadde directory
     */
    protected function rollbackFiles($remote_host, $remote_dir, $target_dir)
    {
        $this->logger->log('rollbackFiles', LOG_DEBUG);

        $this->remote_shell->exec('cd ' . $remote_dir . '; rm -rf ' . $target_dir, $remote_host);
    }

    protected function checkTargetSpecificFiles($action)
    {
        if (Deploy::UPDATE == $action) {
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
                        throw new DeployException("$current_filepath does not exist");
                    }
                }
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
     * Bereidt de --copy-dest parameter voor rsync voor als dat van toepassing is
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
}
