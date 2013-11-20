<?php

use LemonWeb\Deployer\Interfaces\SqlUpdateInterface;


/**
 * Very early timestamp to make sure this patch is executed first because it is needed to register all other patches.
 */
class sql_19700101_000000_dbpatcher implements SqlUpdateInterface
{
    public function isActive()
    {
        return true;
    }

    public function getType()
    {
        return self::TYPE_SMALL;
    }

    public function up()
	{
		return '
			CREATE TABLE `db_patches` (
			  `id` INT UNSIGNED AUTO_INCREMENT,
              `patch_name` varchar(400) COLLATE ascii_general_ci NOT NULL,
              `patch_timestamp` varchar(14) NOT NULL,
              `down_sql` TEXT NOT NULL,
              `applied_at` datetime DEFAULT NULL,
              `reverted_at` datetime DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE (`patch_name`)
            );
		';
	}

	public function down()
	{
		return '';
	}
}
