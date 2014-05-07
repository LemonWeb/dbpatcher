<?php

namespace LemonWeb\Deployer\Shell;


interface LocalShellInterface
{
    /**
     * @param bool $autoDefaults        Automatically choose the default option in inputPrompt() (for unattended installation)
     */
    public function __construct($autoDefaults = false);

    /**
     * Asks the user for input
     *
     * @param string $message
     * @param string $default
     * @param boolean $isPassword
     * @param array $choices
     * @return string
     */
    public function inputPrompt($message, $default = '', $isPassword = false, $choices = null);
}
