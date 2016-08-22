<?php

namespace LemonWeb\Deployer\Database\Drivers;

use LemonWeb\Deployer\Logger\LoggerInterface;

/**
 * Shared methods across all database drivers.
 */
abstract class BaseDriver implements DriverInterface
{
    /**
     * @var \LemonWeb\Deployer\Logger\LoggerInterface
     */
    protected $logger;

    /**
     * @var \mysqli|\PDO|resource
     */
    protected $connection;

    /**
     * @var string
     */
    protected $username = '';

    /**
     * @var string
     */
    protected $password = '';

    /**
     * @var string
     */
    protected $hostname = '';

    /**
     * @var int
     */
    protected $port = null;

    /**
     * @var string
     */
    protected $database = '';

    /**
     * @var string
     */
    protected $charset = null;

    /**
     * @var int
     */
    protected $transaction_count = 0;

    /**
     * @var string
     */
    protected $last_error = null;

    /**
     * Returns an instance of the database connector
     *
     * @param \LemonWeb\Deployer\Logger\LoggerInterface $logger
     * @param string $hostname
     * @param integer $port
     * @param string $username
     * @param string $password
     * @param string $database
     * @param string $charset
     */
    public function __construct(LoggerInterface $logger, $hostname, $port = null, $username = null, $password = null, $database = null, $charset = null)
    {
        $this->logger = $logger;

        $this->set_connection($hostname, $port, $username, $password);
        $this->set_database($database);
        $this->charset = $charset;
    }

    /**
     * Returns the connection object
     *
     * @return \mysqli|\PDO|resource
     */
    protected function get_connection()
    {
        return $this->connection;
    }

    /**
     * Sets the database variable
     *
     * @param string $database
     */
    protected function set_database($database)
    {
        $this->database = $database;
    }

    /**
     * @return string
     */
    public function getLastError()
    {
        return $this->last_error;
    }

    public function getNamedLock($lock_name, $timeout = 10)
    {
        $lock_name = $this->getLockName($lock_name);
        $sql = "SELECT GET_LOCK('$lock_name', $timeout) AS locked";
        $rs = $this->query($sql);
        $result = $this->fetchAssoc($rs);

        return $result['locked'] == 1 ? true : false;
    }

    public function releaseNamedLock($lock_name)
    {
        $lock_name = $this->getLockName($lock_name);
        $this->query("DO RELEASE_LOCK('$lock_name')");
    }

    /**
     * Prefix the lock name because named locks are server wide.
     *
     * @param string $lock_name
     * @return string
     */
    protected function getLockName($lock_name)
    {
        return 'deployer_' . $lock_name;
    }
}
