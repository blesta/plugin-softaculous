<?php
include_once dirname(__FILE__) . DS . 'softaculous_installer.php';
error_reporting(E_ALL);
ini_set('display_errors', 1);
class IspmanagerInstaller extends SoftactulousInstaller
{

    /**
     * Validates informations and runs a softaculous installation script on ISPmanager
     *
     * @param stdClass $service An object representing the ISPmanager service to execute a script for
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

        // Authenticate in to ISPmanager account
        $loginData = [
            'username' => $serviceFields['ispmanager_username'],
            'password' => $serviceFields['ispmanager_password'],
            'lang' => 'en',
            'forget' => 'on',
            'func' => 'auth'
        ];
        $hostUrl = ($meta->use_ssl == 'true' ? 'https' : 'http') . '://' . $meta->host_name . ':1500/ispmgr?startform=softaculous.redirect&sok=ok';

        $loginResponse = $this->request($hostUrl, $loginData);

        if (empty($loginResponse)) {
            $errorMessage = Language::_('SoftaculousPlugin.remote_error', true);
            $this->Input->setErrors(['login' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        // ISPmanager by default creates a robots.txt file on new accounts, making it necessary to overwrite the existing files
        //$configOptions['overwrite_existing'] = 1;

        // Install script
        $login = ($meta->use_ssl == 'true' ? 'https' : 'http') . '://' . $meta->host_name . '/softaculous/index.php?act=home&api=serialize';
        $response = $this->request($login, $configOptions);

        print_r(strip_tags($response)); exit;

        return $this->installScript(
            isset($serviceFields['ispmanager_domain']) ? $serviceFields['ispmanager_domain'] : '',
            $client->email,
            $login,
            $configOptions
        );
    }

    private function request($url, $params = [])
    {
        if (!isset($this->cookie)) {
            $this->cookie = fopen('php://temp', 'w');
            $this->request = curl_init();
        }

        curl_setopt($this->request, CURLOPT_URL, $url);
        curl_setopt($this->request, CURLOPT_VERBOSE, 0);
        curl_setopt($this->request, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->request, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($this->request, CURLOPT_POST, true);

        if (!empty($params)) {
            curl_setopt($this->request, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt($this->request, CURLOPT_HEADER, true);
        curl_setopt($this->request, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->request, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->request, CURLOPT_COOKIEJAR, $this->cookie);
        curl_setopt($this->request, CURLOPT_COOKIEFILE, $this->cookie);

        $response = curl_exec($this->request);

        return $response;
    }
}
