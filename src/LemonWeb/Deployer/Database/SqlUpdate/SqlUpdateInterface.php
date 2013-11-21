<?php

namespace LemonWeb\Deployer\Database\SqlUpdate;

/**
 * All SQL updates must implement this interface, usually by extending AbstractSqlUpdate.
 * An example:
 *
 *
 * sql_20110104_164856.php:
 *
 * <?php
 *
 * use LemonWeb\Deployer\Database\SqlUpdate\AbstractSqlUpdate;
 *
 *
 * class sql_20110104_164856 implements AbstractSqlUpdate
 * {
 *      public function isActive()
 *      {
 *          return true;
 *      }
 *
 *      public function getType()
 *      {
 *          return self::TYPE_SMALL;
 *      }
 *
 *      public function up()
 *      {
 *          return "
 *              CREATE TABLE `tag` (
 *              `id` int(11) NOT NULL auto_increment,
 *              `name` varchar(100) collate utf8_unicode_ci default NULL,
 *              `seo_title` varchar(255) collate utf8_unicode_ci default NULL,
 *              `seo_description` varchar(255) collate utf8_unicode_ci default NULL,
 *              `confirmed` smallint(5) unsigned NOT NULL default '0',
 *              PRIMARY KEY  (`id`),
 *              UNIQUE KEY `name` (`name`)
 *              ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
 *          ";
 *      }
 *
 *      public function down()
 *      {
 *          return "
 *              DROP TABLE `tag`;
 *          ";
 *      }
 * }
 *
 * To ensure predictable behavior the updates are always executed chronologically, and in reverse order in case of a rollback.
 */
interface SqlUpdateInterface
{
    /**
     * Indicates this patch is small and can be deployed at any time.
     */
    const TYPE_SMALL = 1;

    /**
     * Indicates this patch is large and must be deployed at a controlled time (eg. during a shutdown of the site).
     */
    const TYPE_LARGE = 2;

    /**
     * If this update should be used or not.
     * This is convenient for creating cleanup-updates (data-destructive) that should be run somewhere in the future.
     *
     * @return boolean
     */
    public function isActive();

    /**
     * Return one of the TYPE_* constants to indicate the size of the patch.
     *
     * @return int
     */
    public function getType();

    /**
     * Returns an SQL statement (series) to make changes to the database.
     * Warning: the statement must always end with a semicolon (";") to allow for concatenation of multiple updates.
     *
     * @return string
     */
    public function up();

    /**
     * Returns an SQL statements (series) that revert the changes of the statements in up().
     * Warning: like up(), this statement must also end with a semicolon (";").
     *
     * @return string
     */
    public function down();

    /**
     * Returns an array of classnames that this patch needs to be performed.
     *
     * @return array
     */
    public function getDependencies();
}
