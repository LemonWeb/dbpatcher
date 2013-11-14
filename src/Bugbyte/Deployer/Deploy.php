<?php

namespace Bugbyte\Deployer;

use Bugbyte\Deployer\Database\Helper;
use Bugbyte\Deployer\Exceptions\DeployException;
use Bugbyte\Deployer\Interfaces\DatabaseManagerInterface;
use Bugbyte\Deployer\Interfaces\LocalShellInterface;
use Bugbyte\Deployer\Interfaces\LoggerInterface;
use Bugbyte\Deployer\Interfaces\RemoteShellInterface;
use Bugbyte\Deployer\Shell\LocalShell;
use Bugbyte\Deployer\Shell\RemoteShell;
use Bugbyte\Deployer\Database\Manager as DatabaseManager;
use Bugbyte\Deployer\Logger\Logger;


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
     * @var bool
     */
    protected $debug = false;

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
	 * The general timestamp of this deployment
	 *
	 * @var integer
	 */
	protected $timestamp = null;

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
	 * The path of gearman-restarter.php relative to the project root
	 *
	 * @var string
	 */
	protected $gearman_restarter = null;

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
	 * Settings of Gearman including the names of the worker functions that must be restarted.
	 *
	 * Example:
	 * 		array(
	 *			'servers' => array(
	 *				array('ip' => 'ip address', 'port' => port number)
	 * 			),
	 * 			'workers' => array(
	 *				'worker1',
	 *				'worker2'
	 * 			)
	 * 		)
	 *
	 * @var array
	 */
	protected $gearman = array();

	/**
	 * Cache for listFilesToRename()
	 *
	 * @var array
	 */
	protected $files_to_rename = array();

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
		$this->project_name	= $options['project_name'];
		$this->basedir		= $options['basedir'];
		$this->remote_host	= $options['remote_host'];
		$this->remote_user	= $options['remote_user'];
        $this->target		= $options['target'];
        $this->remote_dir	= $options['remote_dir'] .'/'. $this->target;
        $this->debug        = isset($options['debug']) ? $options['debug'] : false;

        $logfile = isset($options['logfile']) ? $options['logfile'] : null;

        // initialize logger
        $this->logger = new Logger($logfile, $this->debug);

        // initialize local shell
        $this->local_shell = new LocalShell();

        // initialize remote shell
        $ssh_path = isset($options['ssh_path']) ? $options['ssh_path'] : trim(`which ssh`);

        $this->remote_shell = new RemoteShell($this->logger, $this->remote_user, $ssh_path);

        // initialize database manager
        if (isset($options['database_dirs'])) {
            $this->database_manager = new DatabaseManager(
                $this->logger,
                $this->local_shell,
                $this->remote_shell,
                $this->basedir,
                is_array($this->remote_host) ? $this->remote_host[0] : $this->remote_host,
                $this->debug
            );

            if (!$this->database_manager instanceof DatabaseManagerInterface) {
                throw new DeployException('Object of type '. get_class($this->database_manager) .' does not implement DatabaseManagerInterface', 1);
            }

            $this->database_manager->setDirs($options['database_dirs']);

            if (isset($options['database_patcher'])) {
                $this->database_manager->setPatcher($options['database_patcher']);
            }

            // als database host niet wordt meegegeven automatisch de eerste remote host pakken.
            $this->database_manager->setHost(
                isset($options['database_host'])
                    ? $options['database_host']
                    : (is_array($this->remote_host) ? $this->remote_host[0] : $this->remote_host),
                isset($options['database_port'])
                    ? $options['database_port']
                    : null
            );

            if (isset($options['database_name'])) {
                $this->database_manager->setDatabaseName($options['database_name']);
            }

            if (isset($options['database_user'])) {
                $this->database_manager->setUsername($options['database_user']);
            }

            if (isset($options['database_pass'])) {
                $this->database_manager->setPassword($options['database_pass']);
            }
        }

        if (isset($options['rsync_excludes'])) {
            $this->rsync_excludes = (array)$options['rsync_excludes'];
        }

        if (isset($options['data_dirs'])) {
            $this->data_dirs = $options['data_dirs'];
        }

        if (isset($options['datadir_patcher'])) {
            $this->datadir_patcher = $options['datadir_patcher'];
        }

        if (isset($options['gearman_restarter'])) {
            $this->gearman_restarter = $options['gearman_restarter'];
        }

        if (isset($options['auto_init'])) {
            $this->auto_init = $options['auto_init'];
        }

        if (isset($options['target_specific_files'])) {
            $this->target_specific_files = $options['target_specific_files'];
        }

        if (isset($options['gearman'])) {
            $this->gearman = $options['gearman'];
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

		$this->rsync_path = isset($options['rsync_path']) ? $options['rsync_path'] : trim(`which rsync`);

		if (!$this->auto_init)
			return;

		$this->initialize();
	}

	/**
	 * Determines the timestamp of the new deployment and those of the latest two
	 */
	protected function initialize()
	{
		$this->logger->log('initialize', LOG_DEBUG);

		// in case of multiple remote hosts use the first
		$remote_host = is_array($this->remote_host) ? $this->remote_host[0] : $this->remote_host;

        $this->timestamp = time();

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

        if ($this->database_manager) {
            $this->database_manager->initialize($this->timestamp, $this->previous_timestamp, $this->last_timestamp);
        }
	}

	/**
	 * Run a dry-run to the remote server to show the changes to be made
	 *
	 * @param string $action		 update or rollback
	 * @throws DeployException
	 * @return bool				 if the user wants to proceed with the deployment
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

		if (isset($this->apc_deploy_version_template)) {
			if (!file_exists($this->apc_deploy_version_template)) {
				throw new DeployException("{$this->apc_deploy_version_template} does not exist.");
			}
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
	 * Depends on running check() first.
	 */
	public function deploy()
	{
		$this->logger->log('deploy', LOG_DEBUG);

		if (!$this->check(self::UPDATE)) {
			return;
        }

		if (is_array($this->remote_host)) {
			// eerst preDeploy draaien per host, dan alle files synchen
			foreach ($this->remote_host as $remote_host) {
				$this->preDeploy($remote_host, $this->remote_dir, $this->remote_target_dir);
				$this->updateFiles($remote_host, $this->remote_dir, $this->remote_target_dir);
			}

			// na de uploads de database prepareren
            if ($this->database_manager) {
                $this->database_manager->update($this->remote_dir, $this->remote_target_dir);
            }

			// als de files en database klaarstaan kan de nieuwe versie geactiveerd worden
			// door de symlinks te updaten en postDeploy te draaien
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

		$this->cleanup();
	}

	/**
	 * Draait de laatste deployment terug
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

			// nadat de symlinks zijn teruggedraaid de database terugdraaien (let op dat de huidige versie dat nog moet doen)
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
	 * Deletes obsolete deployment directories
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
			if ($this->local_shell->inputPrompt('Delete old directories? (y/n) [n]: ', 'n', false, array('y', 'n')) == 'y') {
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
		if (!isset($this->apc_deploy_version_template, $this->apc_deploy_version_path, $this->apc_deploy_setrev_url) ||
		    !($this->apc_deploy_version_template && $this->apc_deploy_version_path && $this->apc_deploy_setrev_url)) {
			return;
        }

		// find the apc_deploy_setrev_url that belongs to this remote_host
		$apc_deploy_setrev_url = is_array($this->apc_deploy_setrev_url)
									? $this->apc_deploy_setrev_url[array_search($remote_host, $this->remote_host)]
									: $this->apc_deploy_setrev_url;

		$output = array();
		$return = null;

		$this->remote_shell->exec($remote_host,
			"cd $remote_dir/$target_dir; ".
				"cat {$this->apc_deploy_version_template} | sed 's/#deployment_timestamp#/{$this->timestamp}/' > {$this->apc_deploy_version_path}.tmp; ".
				"mv {$this->apc_deploy_version_path}.tmp {$this->apc_deploy_version_path}; ".
				"curl -s -S {$apc_deploy_setrev_url}?rev={$this->timestamp}",
			$output, $return);

		$this->logger->log($output);

		if (0 != $return) {
			$this->logger->log("$remote_host: Clear cache failed");
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
            $this->rsync_path .' -azcO --force --dry-run --delete --progress '. $this->prepareExcludes() .' ./ '.
                $this->remote_user .'@'. $remote_host .':'. $remote_dir .'/'. $this->last_remote_target_dir, 'Rsync check is mislukt'
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
            $this->rsync_path .' -azcO --force --delete --progress '. $this->prepareExcludes() .' '. $this->prepareLinkDest($remote_dir) .' ./ '.
                $this->remote_user .'@'. $remote_host .':'. $remote_dir .'/'. $target_dir
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
            "cd $remote_dir/{$target_dir}; ".
            "php {$this->datadir_patcher} --datadir-prefix={$this->data_dir_prefix} --previous-dir={$this->last_remote_target_dir} ". implode(' ', $this->data_dirs);

        $output = array();
        $return = null;
        $this->remote_shell->exec($remote_host, $cmd, $output, $return);

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

        $this->remote_shell->exec($remote_host, $cmd, $output, $return);
	}

	/**
	 * Verwijdert de laatst geuploadde directory
	 */
	protected function rollbackFiles($remote_host, $remote_dir, $target_dir)
	{
		$this->logger->log('rollbackFiles', LOG_DEBUG);

		$output = array();
		$return = null;
		$this->remote_shell->exec($remote_host, 'cd '. $remote_dir .'; rm -rf '. $target_dir, $output, $return);
	}

	/**
	 * Update de production-symlink naar de nieuwe (of oude, bij rollback) upload directory
	 */
	protected function changeSymlink($remote_host, $remote_dir, $target_dir)
	{
		$this->logger->log('changeSymlink', LOG_DEBUG);

		$output = array();
		$return = null;
		$this->remote_shell->exec($remote_host, "cd $remote_dir; rm production; ln -s {$target_dir} production", $output, $return);
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

			$output = array();
			$return = null;
			$this->remote_shell->exec($remote_host, "cd {$remote_dir}/{$this->remote_target_dir}; $target_files_to_move", $output, $return);
		}
	}

	/**
	 * Maakt een lijst van de files die specifiek zijn voor een clusterrol of doel en op de doelserver hernoemd moeten worden
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

			// doelspecifieke files hernoemen
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

			// controleren of alle files bestaan
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
		$this->remote_shell->exec($remote_host, "ls -1 $remote_dir", $dirs, $return);

		if (0 !== $return) {
			throw new DeployException('ssh initialize failed');
		}

		if (!count($dirs)) {
            return array();
        }

        $deployment_dirs = array();

        foreach ($dirs as $dirname) {
            if (preg_match('/'. preg_quote($this->project_name) .'_\d{4}-\d{2}-\d{2}_\d{6}/', $dirname)) {
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
            $time = strtotime(str_replace(array($this->project_name .'_', '_'), array('', ' '), $dirname));

            // deployments older than a month can go
            if ($time < strtotime('-1 month')) {
                $this->logger->log("$dirname is older than a month");

                $dirs_to_delete[] = $dirname;
            } elseif ($time < strtotime('-1 week')) {
                // of deployments older than a week only the last one of the day stays
                if (isset($deployment_dirs[$key+1])) {
                    $time_next = strtotime(str_replace(array($this->project_name .'_', '_'), array('', ' '), $deployment_dirs[$key+1]));

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
	 * Wrapper for rsync command's
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
