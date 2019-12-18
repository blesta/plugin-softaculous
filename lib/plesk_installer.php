<?php
include_once dirname(__FILE__) . DS . 'softaculous_installer.php';
class PleskInstaller extends SoftactulousInstaller
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
            'login_name' => isset($meta->username) ? $meta->username : '',
            'passwd' => isset($meta->password) ? $meta->password : ''
        ];
        $hostName = isset($meta->host_name) ? $meta->host_name : '';
        $port = isset($meta->port) ? $meta->port : '';
        $loginUrl = 'https://' . $hostName . ':' . $port . '/login_up.php3';
        $this->post($loginData, $loginUrl, 'POST');

        // List of Scripts
        $scripts = $this->softaculousScripts();
        $installationScript = (!empty($configOptions['script']) ? $configOptions['script'] : '');
        // Which Script are we to install ?
        foreach ($scripts as $key => $value) {
            if (trim(strtolower($value['name'])) == trim(strtolower($installationScript))) {
                $sid = $key;
                break;
            }
        }
        // Did we find the Script ?
        if (empty($sid)) {
            $errorMessage = Language::_('SoftaculousPlugin.script_selected_error', true, $installationScript);
            $this->Input->setErrors(['script_id' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        // Install the script
        $data = [
            'softdomain' => (!empty($serviceFields['plesk_domain']) ? $serviceFields['plesk_domain'] : ''),
            // OPTIONAL - By default it will be installed in the /public_html folder
            'softdirectory' => (!empty($configOptions['directory']) ? $configOptions['directory'] : ''),
            'admin_username' => (!empty($configOptions['admin_name']) ? $configOptions['admin_name'] : ''),
            'admin_pass' => (!empty($configOptions['admin_pass']) ? $configOptions['admin_pass'] : ''),
            'admin_email' => $client->email
        ];
        $response = $this->scriptInstallRequest(
            $sid,
            'https://' . $hostName . ':' . $port . '/modules/softaculous/index.php',
            $data
        );

        if (isset($response->done) && $response->done) {
            return true;
        }

        $errorMessage = Language::_(
            'SoftaculousPlugin.script_no_installed',
            true,
            (isset($response->error) ? json_encode($response->error) : '')
        );
        $this->Input->setErrors(['script_id' => ['invalid' => $errorMessage]]);
        $this->logger->error($errorMessage);
        return false;
    }
}