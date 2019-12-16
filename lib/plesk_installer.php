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
        $loginData = ['login_name' => $meta->username, 'passwd' => $meta->password];
        $loginUrl = 'https://' . $meta->host_name . ':' . $meta->port . '/login_up.php3';
        $this->post($loginData, $loginUrl, 'POST');

        // List of Scripts
        $scripts = $this->softaculousScripts();
        $installationScript = $configOptions['script'];
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
            'softdomain' => $serviceFields['plesk_domain'],
            // OPTIONAL - By default it will be installed in the /public_html folder
            'softdirectory' => (!empty($configOptions['directory']) ? $configOptions['directory'] : ''),
            'admin_username' => $configOptions['admin_name'],
            'admin_pass' => $configOptions['admin_pass'],
            'admin_email' => $client->email
        ];
        $response = $this->scriptInstallRequest(
            $sid,
            'https://' . $meta->host_name . ':' . $meta->port . '/modules/softaculous/index.php',
            $data
        );
        if ('installed' == strtolower($response)) {
            return true;
        }

        $decodedResponse = json_decode($response);
        if (isset($decodedResponse->done) && $decodedResponse->done) {
            return true;
        }

        $errorMessage = Language::_(
            'SoftaculousPlugin.script_no_installed',
            true,
            (isset($decodedResponse->error) ? json_encode($decodedResponse->error) : '')
        );
        $this->Input->setErrors(['script_id' => ['invalid' => $errorMessage]]);
        $this->logger->error($errorMessage);
        return false;
    }

    /**
     * Send a request to the Plesk.
     *
     * @param array $post The parameters to include in the request
     * @param string $url Specifies the url to invoke
     * @param string $method Http request method (GET, DELETE, POST)
     * @return string An json formatted string containing the response
     */
    private function post(array $post, $url, $method = 'GET')
    {
        $ch = curl_init();
        // Set the request method and parameters
        switch (strtoupper($method)) {
            case 'GET':
            case 'DELETE':
                $url .= empty($post) ? '' : '?' . http_build_query($post);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
                curl_setopt($ch, CURLOPT_POST, 1);
            default:
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
                break;
        }
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        // Create new session cookies
        curl_setopt($ch, CURLOPT_COOKIESESSION, true);

        // Check the Header
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Get response from the server.
        $response = curl_exec($ch);
        $this->setCookie($response);
        $error = curl_error($ch);
        if ($error !== '') {
            $errorMessage = Language::_('SoftaculousPlugin.remote_curl_error', true, $error);
            $this->logger->error($errorMessage);
            return;
        }

        $curlInfo = curl_getinfo($ch);
        curl_close($ch);

        return trim(substr($response, $curlInfo['header_size']));
    }

    /**
     * Parses and saves cookies from a response
     *
     * @param string $response
     */
    private function setCookie($response)
    {
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);
        $cookies = array();
        foreach($matches[1] as $item) {
            parse_str($item, $cookie);
            $cookies = array_merge($cookies, $cookie);
        }
        foreach ($cookies as $cookie => $value) {
            $this->cookie = $cookie . '=' . $value;
        }
    }
}