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
        $configOptions = array_merge($configOptions, $this->getToken($api));

        // Install script
        $login = isset($urlResponse->location) ? $urlResponse->location : $login;

        // Set installer options
        $this->setOptions(
            [
                'request' => [
                    'raw' => false,
                    'referer' => $login . '?api=serialize&act=software'
                ]
            ]
        );

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
     * @return array An array contaning the CSRF token and soft status key
     */
    private function getToken($url)
    {
        // Set the options for the current request
        $this->setOptions(['request' => ['raw' => true]]);

        $params = [
            'act' => 'software',
            'soft' => 26
        ];
        $tokenResponse = $this->makeRequest($params, $url, 'GET');

        $csrf_token = explode('name="csrf_token" value="', $tokenResponse, 2);
        $csrf_token = explode('" />', (isset($csrf_token[1]) ? $csrf_token[1] : ''), 2);

        $soft_status_key = explode('id="soft_status_key" value="', $tokenResponse, 2);
        $soft_status_key = explode('" />', (isset($soft_status_key[1]) ? $soft_status_key[1] : ''), 2);

        return [
            'csrf_token' => isset($csrf_token[0]) ? trim($csrf_token[0]) : '',
            'soft_status_key' => isset($soft_status_key[0]) ? trim($soft_status_key[0]) : ''
        ];
    }
}
