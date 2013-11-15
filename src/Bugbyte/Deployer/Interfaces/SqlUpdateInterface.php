<?php

namespace Bugbyte\Deployer\Interfaces;

/**
 * All SQL updates must implement this interface.
 * An example:
 *

	sql_20110104_164856.class.php:

	<?php

    use Bugbyte\Deployer\Interfaces\SqlUpdateInterface;


	class sql_20110104_164856 implements SqlUpdateInterface
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
			return "
				CREATE TABLE `tag` (
				  `id` int(11) NOT NULL auto_increment,
				  `name` varchar(100) collate utf8_unicode_ci default NULL,
				  `seo_title` varchar(255) collate utf8_unicode_ci default NULL,
				  `seo_description` varchar(255) collate utf8_unicode_ci default NULL,
				  `confirmed` smallint(5) unsigned NOT NULL default '0',
				  PRIMARY KEY  (`id`),
				  UNIQUE KEY `name` (`name`)
				) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
			";
		}

		public function down()
		{
			return "
				DROP TABLE `tag`;
			";
		}
	}

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
	 * This is handy for creating cleanup-updates (data-destructive) that should be run somewhere in the future.
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
	 * Geeft de SQL statements terug die moeten worden uitgevoerd om de database te upgraden naar deze timestamp.
	 * Let op: de query moet altijd eindigen met een ; want als er meerdere updates moeten worden uitgevoerd worden ze allemaal aan elkaar gekoppeld.
	 *
	 * @returns string
	 */
	public function up();

	/**
	 * Geeft de SQL statements terug die de wijzigingen van up() ongedaan maken
	 * Let op: de query moet altijd eindigen met een ; want als er meerdere updates moeten worden uitgevoerd worden ze allemaal aan elkaar gekoppeld.
	 *
	 * @returns string
	 */
	public function down();
}
