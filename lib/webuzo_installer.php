<?php
include_once dirname(__FILE__) . DS . 'softaculous_installer.php';

class WebuzoInstaller extends SoftactulousInstaller
{

    /**
     * Validates informations and runs a softaculous installation script on Webuzo
     *
     * @param stdClass $service An object representing the Webuzo service to execute a script for
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
            'user' => $serviceFields['webuzo_username'],
            'pass' => $serviceFields['webuzo_password'],
            'goto_uri' => '/'
        ];
        $hostName = isset($meta->host) ? $meta->host : '';
        $loginResponse = $this->makeRequest(
            $loginData,
            'https://' . $hostName . ':2003/index.php?api=json',
            'POST'
        );

        if ($loginResponse == null) {
            $errorMessage = Language::_('SoftaculousPlugin.remote_error', true);
            $this->Input->setErrors(['login' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        $parsed = ['path' => ''];
        if (!empty($loginResponse->redirect_url)) {
            $parsed = parse_url($loginResponse->redirect_url);
        } elseif (!empty($loginResponse->url)) {
            $parsed = parse_url($loginResponse->url);
        }

        $path = trim(dirname($parsed['path']));
        $path = rtrim($path[0] == '/' ? $path : '/' . $path, '/');

        // Make the Login system
        $login = 'https://' . rawurlencode($serviceFields['webuzo_username']) . ':'
            . rawurlencode($serviceFields['webuzo_password']) . '@' . $meta->host . ':2003'
            . $path . '/softaculous/index.php';

        return $this->installScript(
            (!empty($serviceFields['webuzo_domain']) ? $serviceFields['webuzo_domain'] : ''),
            $client->email,
            $login,
            $configOptions
        );
    }
}
