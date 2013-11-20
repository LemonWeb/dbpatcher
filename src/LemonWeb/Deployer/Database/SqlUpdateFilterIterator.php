<?php /* Copyright © LemonWeb B.V. All rights reserved. $$Revision:$ */

namespace LemonWeb\Deployer\Database;

use LemonWeb\Deployer\Exceptions\DeployException;


class SqlUpdateFilterIterator extends \FilterIterator
{
    /**
     * Determines if the current entry is a valid SQL update file.
     *
     * @return bool
     */
    public function accept()
    {
        $filename = $this->getFilename();

        if ($this->isDot() || !$this->isFile() || substr($filename, -4) != '.php') {
            return false;
        }

        if (!preg_match('/sql_(\d{8}_\d{6})/', $filename)) {
            return false;
        }

        try {
            $check = Helper::checkFiles($this->getPath(), array($filename));

            if (count($check) == 0) {
                return false;
            }
        } catch (DeployException $exception) {
            return false;
        }

        return true;
    }

    public function key()
    {
        return Helper::convertFilenameToDateTime($this->getFilename());
    }
}
