<?php /* Copyright © LemonWeb B.V. All rights reserved. $$Revision:$ */

namespace Bugbyte\Deployer\Interfaces;


interface DatabaseDriverInterface
{
    /**
     * Checks if the driver's required extension is available.
     *
     * @return boolean
     */
    public function checkExtension();

    /**
     * Returns an instance of the database connector.
     *
     * @param \Bugbyte\Deployer\Interfaces\LoggerInterface $logger
     * @param string $hostname
     * @param integer $port
     * @param string $username
     * @param string $password
     * @param string $database
     * @param string $charset
     */
    public function __construct(LoggerInterface $logger, $hostname, $port = null, $username = null, $password = null, $database = null, $charset = null);

    public function connect();

    /**
     * @param string $sql
     * @return mixed
     */
    public function query($sql);

    /**
     * @param mixed $var
     * @return mixed
     */
    public function escape($var);

    /**
     * Starts a transaction.
     */
    public function startTransaction();

    /**
     * Commit a transaction.
     */
    public function doCommit();

    /**
     * Rollback a transaction.
     */
    public function doRollback();

    /**
     * @return string
     */
    public function getLastError();

    /**
     * Request a named lock (MYSQL GET_LOCK() http://dev.mysql.com/doc/refman/5.0/en/miscellaneous-functions.html#function_get-lock)
     *
     * @param string $lock_name
     * @param int $timeout
     * @return bool
     */
    public function getNamedLock($lock_name, $timeout = 10);

    /**
     * Release a named lock (MYSQL RELEASE_LOCK() http://dev.mysql.com/doc/refman/5.0/en/miscellaneous-functions.html#function_release-lock)
     *
     * @param string $lock_name
     */
    public function releaseNamedLock($lock_name);

    /**
     * @param $result
     * @return integer
     */
    public function numRows($result);

    /**
     * @param $result
     * @return array
     */
    public function fetchAssoc($result);

    /**
     * @return integer
     */
    public function affectedRows();

    /**
     * @return integer
     */
    public function lastInsertId();
} 