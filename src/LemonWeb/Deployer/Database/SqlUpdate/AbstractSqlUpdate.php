<?php /* Copyright © LemonWeb B.V. All rights reserved. $$Revision:$ */

namespace LemonWeb\Deployer\Database\SqlUpdate;

abstract class AbstractSqlUpdate implements SqlUpdateInterface
{
    public function isActive()
    {
        return true;
    }

    public function getType()
    {
        return SqlUpdateInterface::TYPE_SMALL;
    }

    public function down()
    {
        return '';
    }

    public function getDependencies()
    {
        return array();
    }
}
