<?php

namespace LemonWeb\Deployer\Other;

use LemonWeb\Deployer\Exceptions\DeployException;

class APC
{
    /**
     * The project path where the deploy_timestamp.php template is located
     *
     * @var string
     */
    protected $apc_deploy_timestamp_template = null;

    /**
     * The absolute physical path where the deploy_timestamp.php should be placed (on the remote server)
     *
     * @var string
     */
    protected $apc_deploy_timestamp_path = null;

    /**
     * The local url (on the remote server) where setrev.php can be reached (one for each remote host)
     *
     * @var string|array
     */
    protected $apc_deploy_setrev_url = null;

    public function __construct(array $options)
    {
        $this->apc_deploy_version_template = $options['apc_deploy_version_template'];
        $this->apc_deploy_version_path = $options['apc_deploy_version_path'];

        if (!(
                is_string($options['remote_host']) &&
                is_string($options['apc_deploy_setrev_url'])
            ) &&
            !(
                is_array($options['remote_host']) &&
                is_array($options['apc_deploy_setrev_url']) &&
                count($options['remote_host']) == count($options['apc_deploy_setrev_url'])
            )
        ) {
            throw new DeployException('apc_deploy_setrev_url must be similar to remote_host (string or array with the same number of elements)');
        }

        $this->apc_deploy_setrev_url = $options['apc_deploy_setrev_url'];
        $this->remote_host = $options['remote_host'];
    }

    public function check($action)
    {
        if (isset($this->apc_deploy_version_template)) {
            if (!file_exists($this->apc_deploy_version_template)) {
                throw new DeployException("{$this->apc_deploy_version_template} does not exist.");
            }
        }
    }

    public function clear($remote_host, $remote_dir, $target_dir)
    {
        if (!isset($this->apc_deploy_version_template, $this->apc_deploy_version_path, $this->apc_deploy_setrev_url) ||
            !($this->apc_deploy_version_template && $this->apc_deploy_version_path && $this->apc_deploy_setrev_url)
        ) {
            return;
        }

        // find the apc_deploy_setrev_url that belongs to this remote_host
        $apc_deploy_setrev_url = is_array($this->apc_deploy_setrev_url)
            ? $this->apc_deploy_setrev_url[array_search($remote_host, $this->remote_host)]
            : $this->apc_deploy_setrev_url;

        $output = array();
        $return = null;

        $this->remote_shell->exec(
            "cd $remote_dir/$target_dir; " .
            "cat {$this->apc_deploy_version_template} | sed 's/#deployment_timestamp#/{$this->timestamp}/' > {$this->apc_deploy_version_path}.tmp; " .
            "mv {$this->apc_deploy_version_path}.tmp {$this->apc_deploy_version_path}; " .
            "curl -s -S {$apc_deploy_setrev_url}?rev={$this->timestamp}",
            $remote_host,
            $output, $return);

        $this->logger->log($output);

        if (0 != $return) {
            $this->logger->log("$remote_host: Clear cache failed");
        }
    }
} 