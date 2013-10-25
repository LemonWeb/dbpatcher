<?php

namespace Bugbyte\Deployer\Interfaces;


interface LocalShellInterface
{
    public function inputPrompt($message, $default = '', $isPassword = false);
}
