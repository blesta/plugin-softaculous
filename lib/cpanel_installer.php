<?php
include_once dirname(__FILE__) . DS . 'softaculous_installer.php';

class CpanelInstaller extends SoftactulousInstaller
{

    /**
     * Validates informations and runs a softaculous installation script on cPanel
     *
     * @param stdClass $service An object representing the cPanel service to execute a script for
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

        // Login and get the security token and cookies
        $loginData = [
            'user' => $serviceFields['cpanel_username'],
            'pass' => $serviceFields['cpanel_password']
        ];
        $hostName = isset($meta->host_name) ? $meta->host_name : '';
        $loginResponse = $this->makeRequest(
            $loginData,
            'https://' . $hostName . ':2083/login/?login_only=1',
            'POST'
        );

        if ($loginResponse == null || empty($loginResponse->security_token)) {
            $errorMessage = Language::_('SoftaculousPlugin.remote_error', true);
            $this->Input->setErrors(['login' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        // Determine the frontend theme from the login redirect, fallback to jupiter
        $themeName = 'jupiter';
        if (!empty($loginResponse->redirect) && strpos($loginResponse->redirect, '/frontend/') !== false) {
            $parsedRedirect = parse_url($loginResponse->redirect);
            $redirectPath = trim(dirname($parsedRedirect['path']));
            $extracted = basename(rtrim($redirectPath, '/'));
            if (!empty($extracted)) {
                $themeName = $extracted;
            }
        }

        // Make the Login system
        $login = 'https://' . $meta->host_name . ':2083' . $loginResponse->security_token
            . '/frontend/' . $themeName . '/softaculous/index.live.php';

        return $this->installScript(
            (!empty($serviceFields['cpanel_domain']) ? $serviceFields['cpanel_domain'] : ''),
            $client->email,
            $login,
            $configOptions
        );
    }
}
