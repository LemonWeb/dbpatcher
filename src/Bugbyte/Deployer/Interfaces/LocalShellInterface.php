<?php

namespace Bugbyte\Deployer\Interfaces;


interface LocalShellInterface
{
    /**
     * Asks the user for input
     *
     * @param string $message
     * @param string $default
     * @param boolean $isPassword
     * @return string
     */
    public function inputPrompt($message, $default = '', $isPassword = false);
}
