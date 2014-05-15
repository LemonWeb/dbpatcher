<?php

use LemonWeb\Deployer\Database\SqlUpdate\AbstractSqlUpdate;


/**
 * Very early timestamp to make sure this patch is executed first because it is needed to register all other patches.
 */
class sql_19700101_000000_dbpatcher extends AbstractSqlUpdate
{
    public function up()
    {
        switch ($this->options['charset']) {
            case 'latin1':
                return '
                    CREATE TABLE `db_patches` (
                      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                      `patch_name` varchar(400) COLLATE latin1_swedish_ci NOT NULL,
                      `patch_timestamp` varchar(14) COLLATE latin1_swedish_ci NOT NULL,
                      `down_sql` TEXT COLLATE latin1_swedish_ci NULL,
                      `dependencies` TEXT COLLATE latin1_swedish_ci NULL,
                      `applied_at` datetime NULL,
                      `reverted_at` datetime NULL,
                      PRIMARY KEY (`id`),
                      UNIQUE `patch_name` (`patch_name`(100))
                    ) DEFAULT CHARSET=latin1 COLLATE latin1_swedish_ci;
                ';

            case 'utf8':
            default:
                return '
                    CREATE TABLE `db_patches` (
                      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                      `patch_name` varchar(400) COLLATE utf8_unicode_ci  NOT NULL,
                      `patch_timestamp` varchar(14) COLLATE utf8_unicode_ci NOT NULL,
                      `down_sql` TEXT COLLATE utf8_unicode_ci NULL,
                      `dependencies` TEXT COLLATE utf8_unicode_ci NULL,
                      `applied_at` datetime NULL,
                      `reverted_at` datetime NULL,
                      PRIMARY KEY (`id`),
                      UNIQUE `patch_name` (`patch_name`(100))
                    ) DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
                ';
        }
    }
}
