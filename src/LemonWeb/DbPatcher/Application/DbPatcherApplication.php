<?php

namespace LemonWeb\DbPatcher\Application;

use LemonWeb\DbPatcher\Command\Update;
use Symfony\Component\Console\Application;

class DbPatcherApplication extends Application
{
    /**
     * @var array
     */
    protected $config;

    public function __construct(array $config)
    {
        parent::__construct('LemonWeb DbPatcher', '2.x');

        $this->config = $config;

        $this->add(new Update());
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        return $this->config;
    }

}
