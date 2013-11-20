<?php

namespace LemonWeb\Deployer\Database;

use LemonWeb\Deployer\Logger\LoggerInterface;
use LemonWeb\Deployer\Shell\LocalShellInterface;
use LemonWeb\Deployer\Shell\RemoteShellInterface;


interface ManagerInterface
{
    public function __construct(LoggerInterface $logger, LocalShellInterface $local_shell, RemoteShellInterface $remote_shell, array $options);

    public function initialize($current_timestamp, $previous_timestamp, $last_timestamp);

    public function check($action);

    public function update($remote_dir, $target_dir);

    public function rollback($remote_dir, $previous_target_dir);

    public function setDirs(array $dirs);

    public function setPatcher($patcher);

    public function setHost($host, $port = null);

    public function setDatabaseName($database_name);

    public function setUsername($username);

    public function setPassword($password);
}
