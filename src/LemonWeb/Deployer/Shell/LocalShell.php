<?php

namespace LemonWeb\Deployer\Shell;


class LocalShell implements LocalShellInterface
{
    protected $autoDefaults = false;

    /**
     * {@inheritdoc}
     */
    public function __construct($autoDefaults = false)
    {
        $this->autoDefaults = $autoDefaults;
    }

    /**
     * {@inheritdoc}
     */
    public function inputPrompt($message, $default = '', $isPassword = false, $choices = null)
    {
        if ($this->autoDefaults) {
            if ($isPassword) {
                $default = '*****';
            }

            $message .= $default . PHP_EOL;
        }

        fwrite(STDOUT, $message);

        if ($this->autoDefaults) {
            return $default;
        }

        if (!$isPassword) {
            $input = trim(fgets(STDIN));
        } else {
            $input = $this->getPassword(false);
            echo PHP_EOL;
        }

        if ($input == '') {
            $input = $default;
        }

        // if possible choices are specified but not met, re-ask the question
        if (null !== $choices && !in_array($input, $choices)) {
            return $this->inputPrompt($message, $default, $isPassword, $choices);
        }

        return $input;
    }

    /**
     * Get a password from the shell.
     *
     * This function works on *nix systems only and requires shell_exec and stty.
     *
     * @author http://www.dasprids.de/blog/2008/08/22/getting-a-password-hidden-from-stdin-with-php-cli
     * @param  boolean $stars Wether or not to output stars for given characters
     * @return string
     */
    protected function getPassword($stars = false)
    {
        // Get current style
        $oldStyle = shell_exec('stty -g');

        if ($stars === false) {
            shell_exec('stty -echo');
            $password = rtrim(fgets(STDIN), "\n");
        } else {
            shell_exec('stty -icanon -echo min 1 time 0');

            $password = '';
            while (true) {
                $char = fgetc(STDIN);

                if ($char === "\n") {
                    break;
                } elseif (ord($char) === 127) {
                    if (strlen($password) > 0) {
                        fwrite(STDOUT, "\x08 \x08");
                        $password = substr($password, 0, -1);
                    }
                } else {
                    fwrite(STDOUT, "*");
                    $password .= $char;
                }
            }
        }

        // Reset old style
        shell_exec('stty ' . $oldStyle);

        // Return the password
        return $password;
    }
}
