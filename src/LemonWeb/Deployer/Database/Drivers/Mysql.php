<?php /* Copyright � LemonWeb B.V. All rights reserved. $$Revision:$ */

namespace LemonWeb\Deployer\Database\Drivers;


/**
 * Database driver which uses PHP's old mysql extension.
 *
 * @deprecated Don't use this old extension anymore, please move to MySQLi or PDO !
 *
 * @method resource get_connection()
 * @property resource $connection
 */
class Mysql extends BaseDriver
{
    /**
     * Checks if the current PHP install has the old mysql extension.
     *
     * @return bool
     */
    public function checkExtension()
    {
        return function_exists('mysql_connect');
    }

    /**
     * Sets the variables, needed for the connection
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

        if ($port) {
            $this->hostname .= ":$port";
        }
    }

    /**
     * Establishes the MySQL connection and returns the connection ID
     *
     * @param string $charset
     * @throws \Exception
     * @return resource connection
     */
    public function connect($charset = null)
    {
        if (!$this->connection = mysql_connect($this->hostname, $this->username, $this->password, false, MYSQL_CLIENT_COMPRESS)) {
            throw new \Exception('Kan geen databaseverbinding maken');
        }

        if (!mysql_select_db($this->database, $this->connection)) {
            throw new \Exception(mysql_error());
        }

        if (null !== $charset) {
            $this->query("SET NAMES '$charset'");
        }

        return $this->connection;
    }

    /**
     * Closes the MySQL connection
     */
    public function disconnect()
    {
        mysql_close($this->connection);
    }

    /**
     * Makes a logfile with all the errors, produced by other functions in this class
     *
     * @param string $query
     * @param string $error
     */
    protected function error($query, $error)
    {
        $this->logger->log($error .' ['. $query .']');

        $this->last_error = $error;
    }

    /**
     * Executes a MySQL query
     *
     * @param string $query
     * @return resource
     */
    public function query($query)
    {
        $this->last_error = null;

        if (!($r = mysql_query($query, $this->connection))) {
            $this->error($query, mysql_error($this->connection));
        }

        return $r;
    }

    public function escape($var)
    {
        return mysql_real_escape_string($var, $this->connection);
    }

    /**
     * transactie starten
     */
    public function startTransaction()
    {
        if ($this->transaction_count == 0) {
            $this->query('START TRANSACTION;');
        }

        $this->transaction_count++;
    }

    /**
     * transactie committen
     */
    public function doCommit()
    {
        $this->transaction_count--;

        //voor de zekerheid
        if ($this->transaction_count < 0) {
            $this->transaction_count = 0;
        }

        if ($this->transaction_count == 0) {
            $this->query('COMMIT;');
        }
    }

    /**
     * transactie rollback
     */
    public function doRollBack()
    {
        $this->transaction_count--;

        //voor de zekerheid
        if ($this->transaction_count < 0) {
            $this->transaction_count = 0;
        }

        if ($this->transaction_count == 0) {
            $this->query('ROLLBACK;');
        }
    }

    public function numRows($result)
    {
        return mysql_num_rows($result);
    }

    public function fetchAssoc($result)
    {
        return mysql_fetch_assoc($result);
    }

    public function affectedRows()
    {
        return mysql_affected_rows($this->connection);
    }

    public function lastInsertId()
    {
        return mysql_insert_id($this->connection);
    }
}
