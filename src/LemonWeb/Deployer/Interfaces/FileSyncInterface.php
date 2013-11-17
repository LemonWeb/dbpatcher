<?php

namespace LemonWeb\Deployer\Interfaces;


interface FileSyncInterface
{
	public function __construct($basedir, $project_name, $remote_host, $remote_user, $remote_dir, $target, $target_specific_files, $rsync_path, array $rsync_excludes, array $data_dirs, $datadir_patcher);

	public function initialize($timestamp);

	public function check($action);
}
