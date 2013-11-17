<?php

namespace LemonWeb\Deployer\Filesync;

use LemonWeb\Deployer\Deploy;
use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Interfaces\FileSyncInterface;


class Manager implements FileSyncInterface
{
	/**
	 * The root directory of the project
	 *
	 * @var string
	 */
	protected $basedir = null;

	/**
	 * The directory of this project on the remote server
	 *
	 * @var string
	 */
	protected $remote_dir = null;

	/**
	 * All files to be used as rsync exclude
	 *
	 * @var array
	 */
	protected $rsync_excludes = array();

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
	 * @param string $basedir
	 * @param string $project_name
	 * @param string $remote_host
	 * @param string $remote_user
	 * @param string $remote_dir
	 * @param string $target                'prod', 'stage'
	 * @param array $target_specific_files
	 * @param string $rsync_path            '/usr/local/bin/rsync'
	 * @param array $rsync_excludes
	 * @param array $data_dirs
	 * @param string $datadir_patcher       'vendor/lemonweb/deployer/bin/datadir-patcher.php'
	 * @throws \LemonWeb\Deployer\Exceptions\DeployException
	 */
	public function __construct($basedir, $project_name, $remote_host, $remote_user, $remote_dir, $target, $target_specific_files, $rsync_path, array $rsync_excludes, array $data_dirs, $datadir_patcher)
	{
		$this->basedir = $basedir;
		$this->remote_dir = $remote_dir .'/'. $target;
		$this->project_name = $project_name;
		$this->remote_host = $remote_host;
		$this->remote_user = $remote_user;
		$this->target = $target;
		$this->target_specific_files = $target_specific_files;

		$this->rsync_path = $rsync_path;
		$this->rsync_excludes = (array) $rsync_excludes;
		$this->data_dirs = (array) $data_dirs;

		if (!file_exists($this->basedir .'/'. $datadir_patcher)) {
			throw new DeployException('Datadir patcher not found');
		}

		$this->datadir_patcher = $datadir_patcher;
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
		$this->logger->log('Initialize remote directory: '. $remote_host .':'. $remote_dir, LOG_INFO, true);

		$output = array();
		$return = null;
		$this->remote_shell->exec($remote_host, "mkdir -p $remote_dir", $output, $return, '', '', LOG_DEBUG);

		if (empty($this->data_dirs)) {
			return;
		}

		$data_dirs = count($this->data_dirs) > 1 ? '{'. implode(',', $this->data_dirs) .'}' : implode(',', $this->data_dirs);

		$cmd = "mkdir -v -m 0775 -p $remote_dir/{$this->data_dir_prefix}/$data_dirs";

		$output = array();
		$return = null;
		$this->remote_shell->exec($remote_host, $cmd, $output, $return, '', '', LOG_DEBUG);
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
		$this->remote_shell->exec($remote_host, "ls -1 $remote_dir", $dirs, $return, '', '', LOG_DEBUG);

		if (0 !== $return) {
			throw new DeployException('ssh initialize failed');
		}

		if (count($dirs)) {
			$past_deployments = array();
			$deployment_timestamps = array();

			foreach ($dirs as $dirname) {
				if (
					preg_match('/'. preg_quote($this->project_name, '/') .'_(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})/', $dirname, $matches) &&
					($time = mktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]))
				) {
					$past_deployments[] = $dirname;
					$deployment_timestamps[] = $time;
				}
			}

			$count = count($deployment_timestamps);

			if ($count > 0)
			{
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
			$this->rsync_path .' -azcO --force --dry-run --delete --progress '. $this->prepareExcludes() .' ./ '.
			$this->remote_user .'@'. $remote_host .':'. $remote_dir .'/'. $this->last_remote_target_dir, 'Rsync check is mislukt'
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
					throw new DeployException('Rsync exclude file not found: '. $exclude);
				}

				$exclude_param .= '--exclude-from='. escapeshellarg($exclude) .' ';
			}
		}

		if (!empty($this->data_dirs)) {
			foreach ($this->data_dirs as $data_dir) {
				$exclude_param .= '--exclude '. escapeshellarg("/$data_dir") .' ';
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
		$this->logger->log('rsyncExec: '. $command, LOG_DEBUG);

		chdir($this->basedir);

		passthru($command, $return);

		$this->logger->log('');

		if (0 !== $return) {
			throw new DeployException($error_msg);
		}
	}

}