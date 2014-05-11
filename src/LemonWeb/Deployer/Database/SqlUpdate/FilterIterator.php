<?php /* Copyright © LemonWeb B.V. All rights reserved. $$Revision:$ */

namespace LemonWeb\Deployer\Database\SqlUpdate;

use LemonWeb\Deployer\Exceptions\DeployException;

class FilterIterator extends \FilterIterator
{
    /**
     * Determines if the current entry is a valid SQL update file.
     *
     * @return bool
     */
    public function accept()
    {
        /** @var \DirectoryIterator $this */
        $filename = $this->getFilename();

        if (!$this->isFile() || substr($filename, -4) != '.php' || preg_match('/^sql_(\d{8}_\d{6})/', $filename) === 0) {
            return false;
        }

        try {
            $check = Helper::checkFiles($this->getPath(), array($filename));

            if (count($check) == 0) {
                return false;
            }
        }
        catch (DeployException $exception) {
            return false;
        }

        return true;
    }

    public function key()
    {
        /** @var \DirectoryIterator $this */
        return Helper::getClassnameFromFilepath($this->getFilename());
    }
}
