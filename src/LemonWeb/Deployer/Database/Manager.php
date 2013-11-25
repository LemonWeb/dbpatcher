<?php

namespace LemonWeb\Deployer\Database;

use LemonWeb\Deployer\Database\SqlUpdate\AbstractSqlUpdate;
use LemonWeb\Deployer\Database\SqlUpdate\FilterIterator;
use LemonWeb\Deployer\Database\SqlUpdate\Helper;
use LemonWeb\Deployer\Deploy;
use LemonWeb\Deployer\Exceptions\DeployException;
use LemonWeb\Deployer\Exceptions\DatabaseException;
use LemonWeb\Deployer\Database\ManagerInterface as DatabaseManagerInterface;
use LemonWeb\Deployer\Shell\LocalShellInterface;
use LemonWeb\Deployer\Shell\RemoteShellInterface;
use LemonWeb\Deployer\Database\SqlUpdate\SqlUpdateInterface;
use LemonWeb\Deployer\Logger\LoggerInterface;

class Manager implements DatabaseManagerInterface
{
    /**
     * @var LoggerInterface
     */
    protected $logger = null;

    /**
     * @var bool
     */
    protected $debug = false;

    /**
     * @var LocalShellInterface
     */
    protected $local_shell = null;

    /**
     * @var RemoteShellInterface
     */
    protected $remote_shell = null;

    /**
     * Het pad van de database patcher, relatief vanaf de project root
     *
     * @var string
     */
    protected $database_patcher = null;

    /**
     * Alle directories die moeten worden doorzocht naar SQL update files
     *
     * @var array
     */
    protected $database_dirs = array();

    /**
     * The root directory of the project
     *
     * @var string
     */
    protected $basedir = null;

    /**
     * The relative path to the deployer's sql_updates directory
     *
     * @var string
     */
    protected $sql_updates_path = null;

    /**
     * The hostname of the server that sends commands to the database server
     *
     * @var string
     */
    protected $control_host = null;

    /**
     * The hostname of the database server
     *
     * @var string
     */
    protected $database_host = null;

    /**
     * The port to use on the database host
     *
     * @var string
     */
    protected $database_port = null;

    /**
     * De naam van de database waar de SQL updates naartoe gaan
     *
     * @var string
     */
    protected $database_name = null;

    /**
     * De gebruikersnaam van de database
     *
     * @var string
     */
    protected $database_user = null;

    /**
     * Het wachtwoord dat bij de gebruikersnaam hoort
     *
     * @var string
     */
    protected $database_pass = null;

    /**
     * Of de database-gegevens gecontroleerd zijn
     *
     * @var boolean
     */
    protected $database_checked = false;

    /**
     * @var integer
     */
    protected $current_timestamp = null;

    /**
     * @var integer
     */
    protected $previous_timestamp = null;

    /**
     * @var integer
     */
    protected $last_timestamp = null;

    /**
     * Indicates if the old patches behavior (timestamps) should be used instead of the new behavior (check against db_patches table).
     * This is enabled automatically if the target database has no db_patches table.
     *
     * @var bool
     */
    protected $patches_table_exists = false;

    /**
     * A list of all patches that should just be registered as done in db_patches without being applied.
     *
     * @var array               [timestamp => filepath, ...]
     */
    protected $patches_to_register_as_done = array();

    /**
     * A list of the patches to apply.
     *
     * @var SqlUpdateInterface[]       With their full relative paths as keys
     */
    protected $patches_to_apply = array();

    /**
     * A list of the patches to revert
     *
     * @var array             timestamps of the patches to revert
     */
    protected $patches_to_revert = array();

    /**
     * Initialization
     *
     * @param LoggerInterface $logger
     * @param LocalShellInterface $local_shell
     * @param RemoteShellInterface $remote_shell
     * @param array $options
     */
    public function __construct(LoggerInterface $logger, LocalShellInterface $local_shell, RemoteShellInterface $remote_shell, array $options)
    {
        $this->logger = $logger;
        $this->local_shell = $local_shell;
        $this->remote_shell = $remote_shell;

        $this->basedir = $options['basedir'];

        $package_dir = str_replace($this->basedir . '/', '', realpath(__DIR__ . '/../../../../'));

        $options = array_merge(array(
            'debug' => false,
            'database_host' => null,
            'database_port' => null,
            'database_name' => null,
            'database_user' => null,
            'database_pass' => null,
            'database_dirs' => null,
            'database_patcher' => "$package_dir/bin/database-patcher.php",
            'control_host' => null
        ), $options);

        $this->debug = $options['debug'];
        $this->control_host = $options['control_host']; // The hostname of the server used to send commands to the database server (TODO move to RemoteShell)
        $this->database_host = $options['database_host']; // The database server (connected to from the control_host)
        $this->database_port = $options['database_port']; // The listening port of the database server
        $this->database_name = $options['database_name']; // The name of the database
        $this->database_user = $options['database_user']; // Login name
        $this->database_pass = $options['database_pass']; // Password
        $this->database_dirs = $options['database_dirs']; // Array of directories where SQL patches are looked for
        $this->database_patcher = $options['database_patcher']; // Path to database-patcher.php

        // determine the relative path to the SQL updates dir of the deployer package
        $this->sql_updates_path = "$package_dir/sql_updates";
    }

    /**
     * Initialization
     *
     * @param string $patcher
     */
    public function setPatcher($patcher)
    {
        $this->database_patcher = $patcher;
    }

    /**
     * Initialization
     *
     * @param array $dirs
     */
    public function setDirs(array $dirs)
    {
        // add the directory of the deployer's own patches
        $deployer_dir = $this->sql_updates_path;

        if (!in_array($deployer_dir, $dirs)) {
            $dirs[] = $deployer_dir;
        }

        $this->database_dirs = $dirs;
    }

    /**
     * Initialization
     *
     * @param string $host
     * @param int $port
     */
    public function setHost($host, $port = null)
    {
        $this->database_host = $host;
        $this->database_port = null !== $port ? $port : 3306;
    }

    /**
     * Initialization
     *
     * @param string $database_name
     */
    public function setDatabaseName($database_name)
    {
        $this->database_name = $database_name;
    }

    /**
     * Initialization
     *
     * @param string $username
     */
    public function setUsername($username)
    {
        $this->database_user = $username;
    }

    /**
     * Initialization
     *
     * @param string $password
     */
    public function setPassword($password)
    {
        $this->database_pass = $password;
    }

    /**
     * @param int $current_timestamp
     * @param int $previous_timestamp
     * @param int $last_timestamp
     */
    public function initialize($current_timestamp, $previous_timestamp = null, $last_timestamp = null)
    {
        $this->current_timestamp = $current_timestamp;
        $this->previous_timestamp = $previous_timestamp;
        $this->last_timestamp = $last_timestamp;
    }

    /**
     * Checks for the existence of the table db_patches
     *
     * @throws \LemonWeb\Deployer\Exceptions\DeployException
     * @return bool
     */
    protected function checkIfPatchTableExists()
    {
        $output = array();
        $return = 0;

        $this->query("SHOW TABLES LIKE 'db_patches'", $output, $return);

        if (0 == $return) {
            if (!empty($output)) {
                $this->logger->log('Check if db_patches exists: yes.', LOG_INFO);

                return true;
            } else {
                $this->logger->log('Check if db_patches exists: no.', LOG_INFO);

                return false;
            }
        }

        throw new DeployException('There was a problem checking for the db_patches table.', 1);
    }

    /**
     * Check if the db_patches table exists and compare it to the local patches.
     *
     * @param string $action update of rollback
     * @throws \LemonWeb\Deployer\Exceptions\DeployException
     */
    public function check($action)
    {
        $this->logger->log('Check for database updates:', LOG_INFO, true);

        if (empty($this->database_dirs)) {
            return;
        }

        // collect and verify the database login information so the db_patches table can be checked
        $this->getDatabaseLogin(true);

        // make a list of all available patchfiles in de project
        $available_patches = $this->findSQLFiles($action);

        $patches_to_apply = array();
        $patches_to_revert = array();
        $patches_to_register_as_done = array();
        $performed_patches = array();
        $dependencies = array();

        if ($this->patches_table_exists = $this->checkIfPatchTableExists()) {
            // remove db_patches patch itself, the table already exists
            if (isset($available_patches['19700101000000'])) {
                unset($available_patches['19700101000000']);
            }

            // get the list of all performed patches from the database
            list($performed_patches, $dependencies) = $this->findPerformedSQLPatches();

            if (Deploy::UPDATE == $action) {
                // list the patches that have not yet been applied
                $patches_to_apply = array_diff_key($available_patches, $performed_patches);

                // list the patchfiles that have been removed from the project and may need to be reverted
                $patches_to_revert = array_diff_key($performed_patches, $available_patches);
            } elseif (Deploy::ROLLBACK == $action) {
                // find the patches that have been performed on the previous deploy
                foreach ($performed_patches as $datetime => $applied_at) {
                    if (($timestamp = strtotime($applied_at)) && $timestamp > $this->previous_timestamp && $timestamp <= $this->last_timestamp) {
                        $patches_to_revert[$datetime] = $datetime;
                    }
                }
            }
        } else {
            // always add the db_patches patch
            $patches_to_apply = array('19700101000000' => $available_patches['19700101000000']);

            unset($available_patches['19700101000000']);

            if (Deploy::UPDATE == $action) {
                // make a list of all patches that could be considered as already applied
                $patches_to_register_as_done = $available_patches;
            }
        }

        ksort($patches_to_apply, SORT_NUMERIC);
        krsort($patches_to_revert, SORT_NUMERIC);
        ksort($patches_to_register_as_done, SORT_NUMERIC);

        // check if the files all contain SQL patches and filter out inactive patches
        $patches_to_apply = array_intersect($patches_to_apply, array_keys(Helper::checkFiles($this->basedir, $patches_to_apply)));
        $patches_to_revert = $this->checkRevertDependencies($patches_to_revert, $dependencies);
        $patches_to_apply = $this->checkDependencies($patches_to_apply, array_keys($performed_patches));

        // nothing needs to be done
        if (empty($patches_to_apply) && empty($patches_to_register_as_done) && empty($patches_to_revert)) {
            $this->logger->log('Database is up to date !');
            return;
        }

        if (!empty($patches_to_revert)) {
            if (!empty($patches_to_revert)) {
                $this->logger->log('Database patches to revert (' . count($patches_to_revert) . '): ' . PHP_EOL . implode(PHP_EOL, array_keys($patches_to_revert)));

                if (count($patches_to_revert) > 1) {
                    $choice = $this->local_shell->inputPrompt('Revert ? (y/p/n) [n]: ', 'n', false, array('y', 'p', 'n'));
                } else {
                    $choice = $this->local_shell->inputPrompt('Revert ? (y/n) [n]: ', 'n', false, array('y', 'n'));
                }

                if ('y' == $choice) {
                    $this->patches_to_revert += $patches_to_revert;
                } elseif ('p' == $choice) {
                    list($picked_revert) = $this->pickPatches($patches_to_revert, array('y', 'n'));
                    $this->patches_to_revert += $picked_revert;
                }
            }
        }

        if (!empty($patches_to_apply)) {
            if (!empty($patches_to_apply)) {
                $patches_list = 'Database patches to apply (' . count($patches_to_apply) . '): ' . PHP_EOL;

                foreach ($patches_to_apply as $patch_filename) {
                    $patches_list .= $patch_filename;

                    $patch_classname = Helper::getClassnameFromFilepath($patch_filename);
                    /** @var AbstractSqlUpdate $patch */
                    $patch = new $patch_classname();

                    if ($patch->getType() == SqlUpdateInterface::TYPE_LARGE) {
                        $patches_list .= " \033[01;31m[Large]\033[0m\n";
                    }

                    $patches_list .= PHP_EOL;
                }

                $this->logger->log($patches_list);

                if (count($patches_to_apply) > 1) {
                    $choice = $this->local_shell->inputPrompt('[a]pply, [r]egister as done, [p]ick, [i]gnore (a/r/p/i) [i]: ', 'i', false, array('a', 'r', 'p', 'i'));
                } else {
                    $choice = $this->local_shell->inputPrompt('[a]pply, [r]egister as done, [i]gnore (a/r/p/i) [i]: ', 'i', false, array('a', 'r', 'i'));
                }

                if ('a' == $choice) {
                    $this->patches_to_apply += $patches_to_apply;
                } elseif ('r' == $choice) {
                    $this->patches_to_register_as_done += $patches_to_apply;
                } elseif ('p' == $choice) {
                    list($picked_apply, $picked_register) = $this->pickPatches($patches_to_apply, array('a', 'r', 'i'));
                    $this->patches_to_apply += $picked_apply;
                    $this->patches_to_register_as_done += $picked_register;
                }
            }
        }

        if (!empty($patches_to_register_as_done)) {
            $patches_to_register_as_done = $this->checkDependencies($patches_to_register_as_done, array_keys($this->patches_to_apply) + array_keys($performed_patches));

            if (!empty($patches_to_register_as_done)) {
                $patches_list = 'Other patches found (' . count($patches_to_register_as_done) . '): ' . PHP_EOL;

                foreach ($patches_to_register_as_done as $patch_filename) {
                    $patches_list .= $patch_filename;

                    $patch_classname = Helper::getClassnameFromFilepath($patch_filename);
                    /** @var AbstractSqlUpdate $patch */
                    $patch = new $patch_classname();

                    if ($patch->getType() == SqlUpdateInterface::TYPE_LARGE) {
                        $patches_list .= " \033[01;31m[Large]\033[0m\n";
                    }

                    $patches_list .= PHP_EOL;
                }

                $this->logger->log($patches_list);

                if (count($patches_to_register_as_done) > 1) {
                    $choice = $this->local_shell->inputPrompt('[a]pply, [r]egister as done, [p]ick, [i]gnore (a/r/p/i) [i]: ', 'i', false, array('a', 'r', 'p', 'i'));
                } else {
                    $choice = $this->local_shell->inputPrompt('[a]pply, [r]egister as done, [i]gnore (a/r/i) [i]: ', 'i', false, array('a', 'r', 'i'));
                }

                if ('a' == $choice) {
                    $this->patches_to_apply += $patches_to_register_as_done;
                } elseif ('r' == $choice) {
                    $this->patches_to_register_as_done += $patches_to_register_as_done;
                } elseif ('p' == $choice) {
                    list($picked_apply, $picked_register) = $this->pickPatches($patches_to_register_as_done, array('a', 'r', 'i'));
                    $this->patches_to_apply += $picked_apply;
                    $this->patches_to_register_as_done += $picked_register;
                }
            }
        }

        if (empty($this->patches_to_apply) && empty($this->patches_to_register_as_done) && empty($this->patches_to_revert)) {
            return;
        }

        $this->getDatabaseLogin();
    }

    /**
     * Prompt the user to choose patch-by-patch what to do with it, and returns the results under the same index as the choices.
     *
     * @param array $patches
     * @param array $choices
     * @return array
     */
    protected function pickPatches(array $patches, array $choices)
    {
        // initialize the return array
        $picks = array();

        foreach ($choices as $key => $choice) {
            $picks[$key] = array();
        }

        // prompt the user to choose for each patch
        foreach ($patches as $key => $value) {
            $choice = $this->local_shell->inputPrompt("$value (". implode('/', $choices) ."): ", '', false, $choices);
            $picks[array_search($choice, $choices)][$key] = $value;
        }

        return $picks;
    }

    /**
     * Removes patches from the array that cannot be performed because of missing dependencies.
     *
     * @param array $patches { timestamp: filename, ... }
     * @param array $performed_patches { timestamp: applied_at, ... }
     * @return array
     */
    protected function checkDependencies(array $patches, array $performed_patches)
    {
        if (empty($patches) || empty($performed_patches)) {
            return $patches;
        }

        $checked_patches = array();

        foreach ($patches as $timestamp => $filename) {
            $classname = Helper::getClassnameFromFilepath($filename);

            /** @var SqlUpdateInterface $patch */
            $patch = new $classname();

            $patch_dependencies = $patch->getDependencies();

            if (empty($patch_dependencies)) {
                $checked_patches[$timestamp] = $filename;
                continue;
            }

            $available_dependencies = array_keys($checked_patches) + array_keys($performed_patches);

            foreach ($patch_dependencies as $dependency_classname) {
                $dependency_timestamp = Helper::convertFilenameToDateTime($dependency_classname);

                if (!in_array($dependency_timestamp, $available_dependencies)) {
                    $this->logger->log("Can't apply patch '$classname', missing dependency '$dependency_classname'.");
                    continue(2);
                }
            }

            $checked_patches[$timestamp] = $filename;
        }

        return $checked_patches;
    }

    /**
     * Removes patches from the array that cannot be reverted because other patches (that are not being reverted) depend upon them.
     *
     * @param array $patches_to_revert
     * @param array $dependencies
     * @return array
     */
    protected function checkRevertDependencies(array $patches_to_revert, array $dependencies)
    {
        if (empty($patches_to_revert) || empty($dependencies)) {
            return $patches_to_revert;
        }

        $checked_patches = array();

        foreach ($patches_to_revert as $patch_timestamp => $patch_applied_at) {
            foreach ($dependencies as $patch_dependencies) {
                if (isset($patch_dependencies[$patch_timestamp])) {
                    $this->logger->log("Can't revert patch '$patch_timestamp' because '{$patch_dependencies[$patch_timestamp]}' needs it.");
                    continue(2);
                } else {
                    $checked_patches[$patch_timestamp] = $patch_applied_at;
                }
            }
        }

        return $checked_patches;
    }

    /**
     * Returns all patches that have already been applied and their dependencies.
     *
     * @throws \LemonWeb\Deployer\Exceptions\DeployException When crashed patches (during patching or reverting) are found
     * @return array [array with performed patches, array with their dependencies]
     */
    protected function findPerformedSQLPatches()
    {
        $this->logger->log('findPerformedSQLPatches', LOG_DEBUG);

        $applied_patches = array();
        $crashed_patches = array();
        $reverted_patches = array();
        $dependencies = array();

        $output = array();

        $this->query('
            SELECT patch_name, patch_timestamp, dependencies, applied_at, reverted_at
            FROM db_patches
            ORDER BY patch_timestamp, id
        ', $output);

        // remove some garbage returned on the first line
        array_shift($output);

        foreach ($output as $patch_record) {
            list($patch_name, $patch_timestamp, $patch_dependencies, $applied_at, $reverted_at) = explode("\t", $patch_record);

            // skip the db_patches patch itself
            if ('19700101000000' == $patch_timestamp) {
                continue;
            }

            if ('NULL' == $applied_at) {
                // this patch crashed while being applied
                $crashed_patches[$patch_timestamp] = $patch_name;
            } elseif ('NULL' != $reverted_at) {
                // this patch crashed while being reverted
                $reverted_patches[$patch_timestamp] = $patch_name;
            } else {
                // this patch was succesfully applied
                $applied_patches[$patch_timestamp] = $applied_at;

                if ('' != $patch_dependencies) {
                    $dependency_names = array();

                    $patch_dependency_names = (array) explode(',', $patch_dependencies);

                    foreach ($patch_dependency_names as $dependency_name) {
                        $dependency_names[Helper::convertFilenameToDateTime($dependency_name)] = $dependency_name;
                    }

                    $dependencies[$patch_timestamp] = $dependency_names;
                }
            }
        }

        if (!empty($crashed_patches)) {
            if (count($crashed_patches) > 1) {
                throw new DeployException('Patches ' . implode(', ', $crashed_patches) . ' have crashed at previous update !');
            } else {
                throw new DeployException('Patch ' . implode(', ', $crashed_patches) . ' has crashed at previous update !');
            }
        }

        if (!empty($reverted_patches)) {
            if (count($reverted_patches) > 1) {
                throw new DeployException('Patches ' . implode(', ', $reverted_patches) . ' have crashed at previous rollback !');
            } else {
                throw new DeployException('Patch ' . implode(', ', $reverted_patches) . ' has crashed at previous rollback !');
            }
        }

        return array($applied_patches, $dependencies);
    }

    /**
     * Make a list of all database patches applied within a timeframe.
     *
     * @param string $latest_timestamp
     * @param string $previous_timestamp
     * @return array
     */
    protected function findPerformedSQLPatchesFromPeriod($latest_timestamp, $previous_timestamp)
    {
        $this->logger->log("findPerformedSQLPatchesFromPeriod($latest_timestamp, $previous_timestamp)", LOG_DEBUG);

        $list = array();
        $output = array();

        $this->query("
            SELECT patch_name, applied_at
            FROM db_patches
            WHERE applied_at BETWEEN '$previous_timestamp' AND '$latest_timestamp'
            ORDER BY patch_timestamp, id
        ", $output);

        foreach ($output as $patch_record) {
            list($patch_name, $applied_at) = explode("\t", $patch_record);

            $list[$applied_at] = $patch_name;
        }

        return $list;
    }

    /**
     * Voert database migraties uit voor de nieuwste upload
     *
     * @param string $remote_dir
     * @param string $target_dir
     */
    public function update($remote_dir, $target_dir)
    {
        $this->logger->log('updateDatabase', LOG_DEBUG);

        if (!$this->database_checked || (empty($this->patches_to_apply) && empty($this->patches_to_revert) && empty($this->patches_to_register_as_done))) {
            return;
        }

        if (!empty($this->patches_to_apply)) {
            $this->sendToDatabase(
                "cd {$remote_dir}/{$target_dir};" .
                " php {$this->database_patcher}" .
                    ' --action="update"' .
                    ' --files="' . implode(',', $this->patches_to_apply) . '"'
            );
        }

        if (!empty($this->patches_to_revert)) {
            $this->sendToDatabase(
                "cd {$remote_dir}/{$target_dir};" .
                " php {$this->database_patcher}" .
                    ' --action="rollback"' .
                    ' --patches="' . implode(',', array_keys($this->patches_to_revert)) . '"'
            );
        }

        if (!empty($this->patches_to_register_as_done)) {
            $this->sendToDatabase(
                "cd {$remote_dir}/{$target_dir};" .
                " php {$this->database_patcher}" .
                    ' --action="update"' .
                    ' --files="' . implode(',', $this->patches_to_register_as_done) . '"' .
                    ' --register-only=1'
            );
        }
    }

    /**
     * Reverts database migrations to the previous deployment
     *
     * @param string $remote_dir
     * @param string $previous_target_dir
     */
    public function rollback($remote_dir, $previous_target_dir)
    {
        $this->logger->log('rollbackDatabase', LOG_DEBUG);

        if (!$this->database_checked) {
            return;
        }

        if (!empty($this->patches_to_apply)) {
            $this->sendToDatabase(
                "cd {$remote_dir}/{$previous_target_dir}; " .
                "php {$this->database_patcher}" .
                    ' --action=update' .
                    ' --files="' . implode(',', array_keys($this->patches_to_apply)) . '"'
            );
        }

        if (!empty($this->patches_to_revert)) {
            $this->sendToDatabase(
                "cd {$remote_dir}/{$previous_target_dir}; " .
                "php {$this->database_patcher}" .
                    ' --action="rollback"' .
                    ' --patches="' . implode(',', $this->patches_to_revert) . '"'
            );
        }
    }

    /**
     * Prompt the user to enter the database name, login and password to use on the remote server for executing the database patches.
     * The credentials are checked by creating and dropping a table.
     *
     * @param bool $pre_check If this is just a check to access te database (to check the db_patches table) or asking for confirmation to send the changes
     */
    protected function getDatabaseLogin($pre_check = false)
    {
        if ($this->database_checked) {
            return;
        }

        $database_name = $this->database_name;

        // if the database credentials are known, no need to ask for them again
        if ($database_name === null) {
            if ($this->local_shell->inputPrompt('Check if database needs updates? (y/n) [n]: ', 'n', false, array('y', 'n')) != 'y') {
                $database_name = 'skip';
            }
        }

        if ('skip' != $database_name) {
            if ($this->database_name !== null) {
                // we're not updating anything yet, so no need to ask questions
                if (!$pre_check) {
                    if ($this->local_shell->inputPrompt('Update database ' . $this->database_name . '? (y/n) [n]: ', 'n', false, array('y', 'n')) != 'y') {
                        $database_name = 'skip';
                    }
                }
            } else {
                $database_name = $this->local_shell->inputPrompt('Database name [skip]: ', 'skip');
            }
        }

        if ('' == $database_name || 'n' == $database_name) {
            $database_name = 'skip';
        }

        if ('skip' == $database_name) {
            $username = '';
            $password = '';

            $this->logger->log('Skip database patches');
        } else {
            $username = $this->database_user !== null ? $this->database_user : $this->local_shell->inputPrompt('Database username [root]: ', 'root');
            $password = $this->database_pass !== null ? $this->database_pass : $this->local_shell->inputPrompt('Database password: ', '', true);

            $return = 0;
            $output = array();

            // Simple access test (check if this user can create and drop a table)
            $this->query("
                CREATE TABLE `temp_" . $this->current_timestamp . "` (`field1` INT NULL);
                DROP TABLE `temp_" . $this->current_timestamp . "`;
            ", $output, $return, $database_name, $username, $password);

            if ($return != 0) {
                $this->getDatabaseLogin();
            }

            $this->logger->log('Database account check passed', LOG_INFO, true);
        }

        $this->database_checked = true;
        $this->database_name = $database_name;
        $this->database_user = $username;
        $this->database_pass = $password;
    }

    /**
     * Send a query to the database.
     *
     * @param string $command
     * @param array $output
     * @param integer $return
     * @throws DatabaseException
     */
    public function sendToDatabase($command, &$output = array(), &$return = 0)
    {
        if ($this->database_checked && 'skip' == $this->database_name) {
            return;
        }

        $this->remote_shell->exec(
            "$command --host=\"{$this->database_host}\"" .
                    " --port={$this->database_port}" .
                    " --user=\"{$this->database_user}\"" .
                    " --pass=\"{$this->database_pass}\"" .
                    " --database=\"{$this->database_name}\"" .
                    " --rootpath=\"{$this->basedir}\"" .
                    " --timestamp=\"" . date(DATE_RSS, $this->current_timestamp) . "\"" .
                    ' --debug=' . ((int)$this->debug),
            $this->control_host,
            $output,
            $return,
            '/ --pass=\\"[^"]+\\" /',
            ' --pass="*****" '
        );

        if (0 !== $return) {
            throw new DatabaseException('Database interaction failed !');
        }
    }

    /**
     * Wrapper for sendToDatabase() to send plain commands to the database
     *
     * @param string $command
     * @param array $output
     * @param int $return
     * @param string $database_name
     * @param string $username
     * @param string $password
     * @throws DatabaseException
     */
    public function query($command, &$output = array(), &$return = 0, $database_name = null, $username = null, $password = null)
    {
        if ($this->database_checked && 'skip' == $this->database_name) {
            return;
        }

        if (null === $database_name) {
            $database_name = $this->database_name;
        }

        if (null === $username) {
            $username = $this->database_user;
        }

        if (null === $password) {
            $password = $this->database_pass;
        }

        $command = str_replace(array( /*'(', ')',*/ /*'`'*/), array( /*'\(', '\)',*/ /*'\`'*/), $command);
        $command = escapeshellarg($command);

        $this->remote_shell->exec(
            "mysql -h{$this->database_host} -P{$this->database_port} -u$username -p$password -e $command --skip-column-names $database_name",
            $this->control_host,
            $output,
            $return,
            '/ -p[^ ]+ /',
            ' -p***** ',
            LOG_DEBUG
        );

        if (0 !== $return) {
            throw new DatabaseException('Database interaction failed !');
        }
    }

    /**
     * Makes a list of all SQL update files, in the order the action implies:
     *   'update': the updates are ordered chronologically (old to new).
     *   'rollback': the updates are ordered in reverse (new to old).
     *
     * @param string $action 'update' or 'rollback'
     * @param boolean $quiet
     * @return array
     */
    public function findSQLFiles($action, $quiet = false)
    {
        $this->logger->log("findSQLFiles($action, " . var_export($quiet, true) . ")", LOG_DEBUG);

        $update_files = array();

        if (!empty($this->database_dirs)) {
            foreach ($this->database_dirs as $database_dir) {
                foreach (new FilterIterator(new \DirectoryIterator($database_dir)) as $timestamp => $entry) {
                    /** @var \SplFileInfo|\DirectoryIterator $entry */

                    $update_files[$timestamp] = $entry->getPathname();
                }
            }

            if (!empty($update_files)) {
                $count_files = count($update_files);

                if (Deploy::UPDATE == $action) {
                    ksort($update_files, SORT_NUMERIC);

                    $this->logger->log($count_files . ' SQL update patch' . ($count_files > 1 ? 'es' : '') . ' found:');
                } elseif (Deploy::ROLLBACK == $action) {
                    krsort($update_files, SORT_NUMERIC);

                    $this->logger->log($count_files . ' SQL rollback patch' . ($count_files > 1 ? 'es' : '') . ' found:');
                }

                $this->logger->log($update_files, LOG_INFO, true);
            } else {
                $this->logger->log('No SQL patches found.');
            }
        }

        return $update_files;
    }
}
