<?php

namespace LemonWeb\Deployer\Database;

use LemonWeb\Deployer\Database\SqlUpdate\Helper as DatabaseHelper;
use LemonWeb\Deployer\Exceptions\DatabaseException;
use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Database\Drivers\DriverInterface as DatabaseDriverInterface;
use LemonWeb\Deployer\Logger\LoggerInterface;
use LemonWeb\Deployer\Database\SqlUpdate\SqlUpdateInterface;

/**
 * Automatically determines which PHP extension to use and deploys database patches.
 */
class Patcher
{
    /**
     * @var \LemonWeb\Deployer\Logger\LoggerInterface
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
     * @param \LemonWeb\Deployer\Logger\LoggerInterface $logger
     * @param string $hostname
     * @param int $port
     * @param string $username
     * @param string $password
     * @param string $database The name of the database
     * @param string $timestamp The current timestamp (to prevent issues with date-differences between the executing server and the database server)
     * @param string $rootpath The root path of the project
     * @throws \LemonWeb\Deployer\Exceptions\DeployException
     */
    public function __construct(LoggerInterface $logger, $hostname, $port, $username, $password, $database, $timestamp, $rootpath)
    {
        $this->logger = $logger;
        $this->database = $database;
        $this->timestamp = $timestamp;
        $this->rootpath = $rootpath;

        // find a usable driver
        foreach (array('Pdo', 'Mysqli') as $drivername) {
            $classname = 'LemonWeb\Deployer\Database\Drivers\\' . $drivername;

            if (!class_exists($classname)) {
                continue;
            }

            $driver = new $classname($logger, $hostname, $port, $username, $password, $database);

            if (!$driver instanceof DatabaseDriverInterface || !$driver->checkExtension()) {
                continue;
            }

            $driver->connect();

            $this->logger->log('Database driver: ' . $drivername, LOG_DEBUG, true);
            $this->driver = $driver;
            break;
        }

        if (null === $this->driver) {
            throw new DeployException('Could not find a usable database driver.', 1);
        }
    }

    /**
     * @param array $files The SQL update files that should be executed
     */
    public function setUpdateFiles($files)
    {
        $this->sql_patch_objects = DatabaseHelper::checkFiles($this->rootpath, $files);
    }

    /**
     * @param array $patches The timestamps of the SQL patches that should be reverted
     */
    public function setRevertPatches($patches)
    {
        $this->sql_revert_patches = $patches;
    }

    /**
     * Applies a series of SQL patches to the database and registers them in the db_patches table.
     *
     * @param bool $register_only Only register the patches as done, don't run their code
     * @throws \LemonWeb\Deployer\Exceptions\DatabaseException
     */
    public function update($register_only = false)
    {
        $this->logger->log('Database update: ' . implode(', ', array_keys($this->sql_patch_objects)), LOG_DEBUG, true);

        if (!count($this->sql_patch_objects)) {
            return;
        }

        foreach ($this->sql_patch_objects as $filename => $sql_patch_object) {
            $patch_timestamp = DatabaseHelper::convertFilenameToDateTime($filename);

            // Register the patch in the db_patches table (except for the db_patches table patch itself, that wouldn't be possible yet).
            // Add the revert (down) code to the record so the update can be reverted when the file doesn't exist, which can happen when code is rolled back.
            // Also add the patch' dependencies list so it won't be reverted before depending patches are reverted.
            if ('19700101000000' != $patch_timestamp) {
                $this->driver->query("
                    INSERT INTO db_patches (
                        patch_name,
                        patch_timestamp,
                        down_sql,
                        dependencies
                    )
                    VALUES (
                        '" . $this->driver->escape($filename) . "',
                        '" . $this->driver->escape($patch_timestamp) . "',
                        '" . $this->driver->escape(trim($sql_patch_object->down())) . "',
                        '" . $this->driver->escape(implode(',', $sql_patch_object->getDependencies())) . "'
                    );
                ");
            }

            // apply the patch
            if (!$register_only) {
                $this->driver->startTransaction();

                $this->driver->multiQuery($sql_patch_object->up());

                if ($mError = $this->driver->getLastError()) {
                    throw new DatabaseException($mError, 1);
                }

                $this->driver->doCommit();
            }

            // if there were no errors, mark the patch as applied
            if ('19700101000000' != $patch_timestamp) {
                $this->driver->query("
                    UPDATE db_patches
                    SET applied_at = '" . $this->driver->escape($this->timestamp) . "'
                    WHERE patch_name = '" . $this->driver->escape($filename) . "';
                ");

                if (!$register_only) {
                    $this->logger->log("Patch '$filename' succeeded.");
                } else {
                    $this->logger->log("Patch '$filename' registered.");
                }
            } else {
                // the db_patches patch has no record set, insert it now
                $this->driver->query("
                    INSERT INTO db_patches (
                        patch_name,
                        patch_timestamp,
                        applied_at
                    )
                    VALUES (
                        '" . $this->driver->escape($filename) . "',
                        '" . $this->driver->escape($patch_timestamp) . "',
                        '" . $this->driver->escape($this->timestamp) . "'
                    );
                ");

                $this->logger->log("Patch '$filename' succeeded.");
            }
        }
    }

    /**
     * Reverts a series of SQL patches by looking up their down_sql code and executing it.
     */
    public function rollback()
    {
        $this->logger->log('Database rollback: ' . implode(', ', $this->sql_revert_patches), LOG_DEBUG);

        if (!count($this->sql_revert_patches)) {
            return;
        }

        foreach ($this->sql_revert_patches as $timestamp) {
            // get the code to revert the patch
            $result = $this->driver->query("
                SELECT id, down_sql
                FROM db_patches
                WHERE patch_timestamp = '" . $this->driver->escape($timestamp) . "'
                ORDER BY applied_at DESC, id DESC;
            ");

            if (!$patch_info = $this->driver->fetchAssoc($result)) {
                return;
            }

            if ($patch_info['down_sql'] != '') {
                // mark the patch as being reverted
                $this->driver->query("
                    UPDATE db_patches
                    SET reverted_at = '" . $this->driver->escape($this->timestamp) . "'
                    WHERE patch_timestamp = '" . $this->driver->escape($timestamp) . "';
                ");

                // revert the patch
                $this->driver->startTransaction();

                $this->driver->query($patch_info['down_sql']);

                if ($error = $this->driver->getLastError()) {
                    throw new DatabaseException($error, 1);
                }

                $this->driver->doCommit();
            }

            // remove the patch from the db_patches table
            $this->driver->query("
                DELETE FROM db_patches
                WHERE id = " . $this->driver->escape($patch_info['id']) . ";
            ");

            $this->logger->log("Patch '$timestamp' reverted.");
        }
    }
}