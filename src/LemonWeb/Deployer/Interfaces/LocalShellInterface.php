<?php

namespace LemonWeb\Deployer\Interfaces;


interface LocalShellInterface
{
    /**
     * Asks the user for input
     *
     * @param string $message
     * @param string $default
     * @param boolean $isPassword
     * @param array $aChoices
     * @return string
     */
    public function inputPrompt($message, $default = '', $isPassword = false, $aChoices = null);
}
