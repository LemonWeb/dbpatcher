<?php /* Copyright � LemonWeb B.V. All rights reserved. $$Revision:$ */

namespace LemonWeb\Deployer\Database\Drivers;

/**
 * Database driver which uses PHP's MySQLi extension.
 *
 * @method \mysqli get_connection()
 * @property \mysqli $connection
 */
class Mysqli extends BaseDriver
{
    /**
     * Checks if the current PHP install has the MySQLi extension.
     *
     * @return bool
     */
    public function checkExtension()
    {
        return class_exists('\mysqli');
    }

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
     * Establishes the MySQL connection and returns the connection
     *
     * @return \mysqli
     */
    public function connect()
    {
        $this->connection = new \mysqli();
        $this->connection->options(MYSQLI_CLIENT_COMPRESS, true);
        $this->connection->real_connect($this->hostname, $this->username, $this->password, $this->database, $this->port);

        if (null !== $this->charset) {
            $this->connection->set_charset($this->charset);
        }

        return $this->connection;
    }

    /**
     * Closes the MySQL connection
     */
    public function disconnect()
    {
        $this->connection->close();
    }

    /**
     * Makes a logfile with all the errors, produced by other functions in this class
     *
     * @param string $query
     * @param string $error
     */
    protected function error($query, $error)
    {
        $this->logger->log($error . ' [' . $query . ']');

        $this->last_error = $error;
    }

    /**
     * Executes a MySQL query
     *
     * @param string $query
     * @return \mysqli_result
     */
    public function query($query)
    {
        $this->last_error = null;

        if (!($r = $this->connection->query($query))) {
            $this->error($query, $this->connection->error);
        }

        return $r;
    }

    public function escape($var)
    {
        return $this->connection->real_escape_string($var);
    }

    public function startTransaction()
    {
        if ($this->transaction_count == 0) {
            $this->connection->autocommit(false);
        }

        $this->transaction_count++;
    }

    public function doCommit()
    {
        $this->transaction_count--;

        //voor de zekerheid
        if ($this->transaction_count < 0) {
            $this->transaction_count = 0;
        }

        if ($this->transaction_count == 0) {
            $this->connection->commit();
            $this->connection->autocommit(true);
        }
    }

    public function doRollBack()
    {
        $this->transaction_count--;

        //voor de zekerheid
        if ($this->transaction_count < 0) {
            $this->transaction_count = 0;
        }

        if ($this->transaction_count == 0) {
            $this->connection->rollback();
            $this->connection->autocommit(true);
        }
    }

    /**
     * @param \mysqli_result $result
     * @return int
     */
    public function numRows($result)
    {
        return $result->num_rows;
    }

    /**
     * @param \mysqli_result $result
     * @return array
     */
    public function fetchAssoc($result)
    {
        if (!$result) {
            return $result;
        }

        return $result->fetch_assoc();
    }

    public function affectedRows()
    {
        return $this->connection->affected_rows;
    }

    public function lastInsertId()
    {
        return $this->connection->insert_id;
    }
}
