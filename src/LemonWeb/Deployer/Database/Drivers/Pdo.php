<?php /* Copyright � LemonWeb B.V. All rights reserved. $$Revision:$ */

namespace LemonWeb\Deployer\Database\Drivers;

/**
 * Database driver which uses PHP's PDO extension.
 *
 * @method \PDO get_connection()
 * @property \PDO $connection
 */
class Pdo extends BaseDriver
{
    /**
     * Checks if the current PHP install has the PDO extension.
     *
     * @return bool
     */
    public function checkExtension()
    {
        return class_exists('\PDO');
    }

    /**
     * @var int
     */
    protected $affected_rows = 0;

    /**
     * Sets the variables needed for the connection
     *
     * @param string $hostname
     * @param integer $port
     * @param string $username
     * @param string $password
     */
    protected function set_connection($hostname, $port, $username, $password)
    {
        $this->username = $username;
        $this->password = $password;
        $this->hostname = $hostname;
        $this->port = $port;
    }

    /**
     * Establishes the PDO connection and returns the connection
     *
     * @return \PDO
     */
    public function connect()
    {
        $dsn = 'mysql:host=' . $this->hostname;

        if ($this->database) {
            $dsn .= ';dbname=' . $this->database;
        }

        if ($this->port) {
            $dsn .= ';port=' . $this->port;
        }

        $this->connection = new \PDO(
            $dsn,
            $this->username,
            $this->password, array(
                \PDO::MYSQL_ATTR_COMPRESS => true,
                \PDO::ATTR_PERSISTENT => false,
            )
        );

        $this->query("SET NAMES {$this->charset}");

        return $this->connection;
    }

    /**
     * Closes the PDO connection
     */
    public function disconnect()
    {
        $this->connection = null;
    }

    /**
     * Makes a logfile with all the errors, produced by other functions in this class
     *
     * @param string $query
     * @param string $error
     */
    protected function error($query, $error)
    {
        if (is_array($error)) {
            $error = print_r($error, true);
        }

        $this->logger->log($error . ' [' . $query . ']', LOG_DEBUG);

        $this->last_error = $error;
    }

    /**
     * Executes an SQL query
     *
     * @param string $query
     * @return \PDOStatement
     */
    public function query($query)
    {
        $this->last_error = null;
        $this->affected_rows = 0;

        $this->logger->log($query, LOG_DEBUG);

        $result = $this->connection->query($query);

        if (false === $result) {
            $errorInfo = $this->connection->errorInfo();
            $this->error($query, "[{$errorInfo[1]}] {$errorInfo[2]}");
        } else {
            $this->affected_rows = $result->rowCount();
        }

        return $result;
    }

    /**
     * Executes multiple SQL queries
     *
     * @param string $queries
     * @return \PDOStatement|bool
     */
    public function multiQuery($queries)
    {
        return $this->query($queries);
    }

    public function escape($var)
    {
        return preg_replace("#^'(.*)'$#", '$1', $this->connection->quote($var));
    }

    public function startTransaction()
    {
        $this->logger->log('start transaction', LOG_DEBUG);

        if ($this->transaction_count == 0) {
            $this->connection->beginTransaction();
        }

        $this->transaction_count++;
    }

    public function doCommit()
    {
        $this->logger->log('commit transaction', LOG_DEBUG);

        $this->transaction_count--;

        //voor de zekerheid
        if ($this->transaction_count < 0) {
            $this->transaction_count = 0;
        }

        if ($this->transaction_count == 0) {
            $this->connection->commit();
        }
    }

    public function doRollBack()
    {
        $this->logger->log('rollback transaction', LOG_DEBUG);

        $this->transaction_count--;

        //voor de zekerheid
        if ($this->transaction_count < 0) {
            $this->transaction_count = 0;
        }

        if ($this->transaction_count == 0) {
            $this->connection->rollback();
        }
    }

    /**
     * @param \PDOStatement $result
     * @return int
     */
    public function numRows($result)
    {
        return $result->rowCount();
    }

    /**
     * @param \PDOStatement $result
     * @return array
     */
    public function fetchAssoc($result)
    {
        if (!$result) {
            return $result;
        }

        return $result->fetch(\PDO::FETCH_ASSOC);
    }

    public function affectedRows()
    {
        return $this->affected_rows;
    }

    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }
}
