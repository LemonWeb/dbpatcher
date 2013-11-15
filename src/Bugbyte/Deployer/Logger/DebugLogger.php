<?php /* Copyright © LemonWeb B.V. All rights reserved. $$Revision:$ */

namespace Bugbyte\Deployer\Logger;

use Bugbyte\Deployer\Interfaces\LoggerInterface;


class DebugLogger implements LoggerInterface
{
    public function __construct($logfile = null, $debug = false)
    {
    }

    public function log($message, $level = LOG_INFO, $extra_newline = false)
    {
        echo $message;
    }

    public function setQuiet($quiet)
    {
    }
}
