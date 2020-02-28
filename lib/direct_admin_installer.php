<?php
include_once dirname(__FILE__) . DS . 'softaculous_installer.php';
class DirectAdminInstaller extends SoftactulousInstaller
{
    /**
     * Validates informations and runs a softaculous installation script on Plesk
     *
     * @param stdClass $service An object representing the Plesk service to execute a script for
     * @param stdClass $meta The module row meta data for the service
     * @return boolean Whether the script succeeded
     */
    public function install(stdClass $service, stdClass $meta)
    {
        if (!isset($this->Clients)) {
            Loader::loadModels($this, ['Clients']);
        }
        if (!isset($this->Form)) {
            Loader::loadComponents($this, ['Form']);
        }

        // Get data for executing script
        $serviceFields = $this->Form->collapseObjectArray($service->fields, 'value', 'key');
        $configOptions = $this->Form->collapseObjectArray($service->options, 'value', 'option_name');
        $client = $this->Clients->get($service->client_id);

        // Login and get the cookies
        $loginData = [
            'username' => isset($serviceFields['direct_admin_username']) ? $serviceFields['direct_admin_username'] : '',
            'password' => isset($serviceFields['direct_admin_password']) ? $serviceFields['direct_admin_password'] : ''
        ];
        $hostName = isset($meta->host_name) ? $meta->host_name : '';
        $port = isset($meta->port) ? $meta->port : '';
        $ssl = isset($meta->use_ssl) && $meta->use_ssl == '1';
        $loginUrl = ($ssl ? 'https://' : 'http://') . $hostName . ':' . $port . '/CMD_LOGIN';
        $this->makeRequest($loginData, $loginUrl, 'POST');

        return $this->installScript(
            (!empty($serviceFields['direct_admin_domain']) ? $serviceFields['direct_admin_domain'] : ''),
            $client->email,
            ($ssl ? 'https://' : 'http://') . $hostName . ':' . $port . '/CMD_PLUGINS/softaculous/index.raw',
            $configOptions
        );
    }
}