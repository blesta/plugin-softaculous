<?php
include_once dirname(__FILE__) . DS . 'softaculous_installer.php';

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
            'authinfo' => $serviceFields['ispmanager_username'] . ':' . $serviceFields['ispmanager_password'],
            'func' => 'auth',
            'out' => 'json',
            'sok' => 'ok'
        ];
        $hostUrl = ($meta->use_ssl == 'true' ? 'https' : 'http') . '://' . $meta->host_name . ':1500/ispmgr';

        $loginResponse = $this->makeRequest(
            $loginData,
            $hostUrl,
            'GET'
        );

        if (!isset($loginResponse->doc->auth->{'$id'})) {
            $errorMessage = Language::_('SoftaculousPlugin.remote_error', true);
            $this->Input->setErrors(['login' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        // Authenticate in to Softaculous
        $session_id = $loginResponse->doc->auth->{'$id'};
        $softaculousData = [
            'auth' => $session_id,
            'func' => 'softaculous.redirect',
            'out' => 'json',
            'sok' => 'ok'
        ];

        $softaculousResponse = $this->makeRequest(
            $softaculousData,
            $hostUrl,
            'GET'
        );

        if (!isset($softaculousResponse->doc->ok->{'$'})) {
            $errorMessage = Language::_('SoftaculousPlugin.remote_error', true);
            $this->Input->setErrors(['login' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        // Get Softaculous login url
        $login = $softaculousResponse->doc->ok->{'$'};
        $query = explode('?', $login, 2);

        $login = isset($query[0]) ? $query[0] : null;
        $query = isset($query[1]) ? $query[1] : null;

        parse_str($query, $query);

        $urlData = [
            'func' => 'redirect',
            'auth' => $query['auth'],
            'authm' => $query['authm'],
            'lang' => $query['lang'],
            'redirect_uri' => $query['redirect_uri'],
            'sok' => 'ok'
        ];

        $urlResponse = $this->makeRequest(
            $urlData,
            $login,
            'GET'
        );

        // Softaculous on ISPmanager requires a CSRF token for each call
        $api = isset($urlResponse->location) ? $urlResponse->location : $login;
        $configOptions['csrf_token'] = $this->getToken($api);

        // Install script
        $login = isset($urlResponse->location) ? $urlResponse->location . 'index.php' : $login;

        return $this->installScript(
            (!empty($serviceFields['ispmanager_domain']) ? $serviceFields['ispmanager_domain'] : ''),
            $client->email,
            $login,
            $configOptions
        );
    }

    /**
     * Get the CSRF token for the next request
     *
     * @param $url The Softaculous API url
     * @return string The CSRF token
     */
    private function getToken($url)
    {
        $params = [
            'act' => 'software',
            'soft' => 26
        ];
        $tokenResponse = $this->makeRequest($params, $url, 'GET', [], true);

        $body = explode('name="csrf_token" value="', $tokenResponse, 2);
        $body = explode('" />', (isset($body[1]) ? $body[1] : ''), 2);

        return isset($body[0]) ? trim($body[0]) : '';
    }
}
