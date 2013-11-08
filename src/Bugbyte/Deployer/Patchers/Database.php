<?php

namespace Bugbyte\Deployer\Patchers;

use Bugbyte\Deployer\Database\Helper as DatabaseHelper;
use Bugbyte\Deployer\Exceptions\DeployException;
use Bugbyte\Deployer\Interfaces\DatabaseDriverInterface;
use Bugbyte\Deployer\Interfaces\LoggerInterface;
use Bugbyte\Deployer\Interfaces\SqlUpdateInterface;

/**
 * Automatically determines which PHP extension to use and deploys database patches.
 */
class Database
{
    /**
     * @var \Bugbyte\Deployer\Interfaces\LoggerInterface
     */
    protected $logger;

    /**
     * @var string
     */
    protected $database;

    /**
     * @var string
     */
    protected $timestamp;

    /**
     * @var SqlUpdateInterface[]
     */
    protected $sql_patch_objects;

    /**
     * @var string[]
     */
    protected $sql_revert_patches;

    /**
     * @var string
     */
    protected $rootpath;

    /**
     * @var DatabaseDriverInterface
     */
    protected $driver = null;

    /**
     * @param \Bugbyte\Deployer\Interfaces\LoggerInterface $logger
     * @param string $hostname
     * @param int $port
     * @param string $username
     * @param string $password
     * @param string $database The name of the database
     * @param string $timestamp The current timestamp (to prevent issues with date-differences between the executing server and the database server)
     * @param string $rootpath The root path of the project
     * @throws \Bugbyte\Deployer\Exceptions\DeployException
     */
    public function __construct(LoggerInterface $logger, $hostname, $port, $username, $password, $database, $timestamp, $rootpath)
    {
        $this->logger = $logger;
        $this->database = $database;
        $this->timestamp = $timestamp;
        $this->rootpath = $rootpath;

        // find a usable driver
        foreach (array('Pdo', 'Mysqli'/*, 'Mysql'*/) as $drivername) {
            $classname = 'Bugbyte\Deployer\Database\Drivers\\'. $drivername;

            if (!class_exists($classname))
            {
                continue;
            }

            $driver = new $classname($logger, $hostname, $port, $username, $password, $database);

            if (!$driver instanceof DatabaseDriverInterface || !$driver->checkExtension())
            {
                continue;
            }

            $driver->connect();

            $this->logger->log('Database driver: '. $drivername, LOG_DEBUG, true);
            $this->driver = $driver;
            break;
        }

        if (null === $this->driver)
        {
            throw new DeployException('Could not find a usable database driver.', 1);
        }
    }

    /**
     * @param array $files          The SQL update files that should be executed
     */
    public function setUpdateFiles($files)
    {
        $this->sql_patch_objects = DatabaseHelper::checkFiles($this->rootpath, $files);
    }

    /**
     * @param array $patches        The timestamps of the SQL patches that should be reverted
     */
    public function setRevertPatches($patches)
    {
        $this->sql_revert_patches = $patches;
    }

    /**
     * Applies a series of SQL patches to the database and registers them in the db_patches table.
     */
    public function update()
    {
        $this->logger->log('Database update: '. implode(', ', array_keys($this->sql_patch_objects)), LOG_DEBUG, true);

        if (!count($this->sql_patch_objects)) {
            return;
        }

        foreach ($this->sql_patch_objects as $filename => $sql_patch_object)
        {
            $patch_timestamp = DatabaseHelper::convertFilenameToDateTime($filename);

            // register the patch in the db_patches table (except for the db_patches table patch itself, that wouldn't be possible yet)
            // add the revert (down) code to the record so the update can be reverted when the file doesn't exist, which can happen when code is rolled back.
            if ('19700101000000' != $patch_timestamp) {
                $this->driver->query("
                    INSERT INTO db_patches (
                        patch_name,
                        patch_timestamp,
                        down_sql
                    )
                    VALUES (
                        '". $this->driver->escape($filename) ."',
                        '". $this->driver->escape($patch_timestamp) ."',
                        '". $this->driver->escape(trim($sql_patch_object->down())) ."'
                    );
                ");
            }

            // apply the patch
            $this->driver->startTransaction();

            $this->driver->query($sql_patch_object->up());

            if ($mError = $this->driver->getLastError())
            {
                var_dump($mError);
                exit;
            }

            $this->driver->doCommit();

            // if there were no errors, mark the patch as applied
            if ('19700101000000' != $patch_timestamp) {
                $this->driver->query("
                    UPDATE db_patches
                    SET applied_at = '". $this->driver->escape($this->timestamp) ."'
                    WHERE patch_name = '". $this->driver->escape($filename) ."';
                ");
            } else {
                // the db_patches patch has no record set, insert it now
                $this->driver->query("
                    INSERT INTO db_patches (
                        patch_name,
                        patch_timestamp,
                        applied_at
                    )
                    VALUES (
                        '". $this->driver->escape($filename) ."',
                        '". $this->driver->escape($patch_timestamp) ."',
                        '". $this->driver->escape($this->timestamp) ."'
                    );
                ");
            }
        }
    }

    /**
     * Reverts a series of SQL patches by looking up their down_sql code and executing it.
     */
    public function rollback()
    {
        $this->logger->log('Database rollback: '. implode(', ', array_keys($this->sql_revert_patches)), LOG_DEBUG);

        if (!count($this->sql_revert_patches)) {
            return;
        }

        foreach ($this->sql_revert_patches as $timestamp)
        {
            // get the code to revert the patch
            $result = $this->driver->query("
                SELECT id, down_sql
                FROM db_patches
                WHERE patch_timestamp = '". $this->driver->escape($timestamp) ."'
                  AND down_sql != ''
                ORDER BY applied_at DESC, id DESC;
            ");

            if (!$patch_info = $this->driver->fetchAssoc($result))
            {
                return;
            }

            var_dump($patch_info);

            // mark the patch as being reverted
            $this->driver->query("
                UPDATE db_patches
                SET reverted_at = '". $this->driver->escape($this->timestamp) ."'
                WHERE patch_timestamp = '". $this->driver->escape($timestamp) ."';
            ");

            // revert the patch
            $this->driver->startTransaction();

            $this->driver->query($patch_info['down_sql']);

            $this->driver->doCommit();

            // remove the patch from the db_patches table
            $this->driver->query("
                DELETE FROM db_patches
                WHERE id = ". $this->driver->escape($patch_info['id']) .";
            ");
        }
    }
}
