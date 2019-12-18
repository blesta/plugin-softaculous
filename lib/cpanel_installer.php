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
        $service_fields = $this->Form->collapseObjectArray($service->fields, 'value', 'key');
        $config_options = $this->Form->collapseObjectArray($service->options, 'value', 'option_name');
        $client = $this->Clients->get($service->client_id);

        // Login and get the cookies
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://' . $meta->host_name . ':2083/login/');
        curl_setopt($ch, CURLOPT_VERBOSE, 0);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $post = ['user' => $service_fields['cpanel_username'],
                'pass' => $service_fields['cpanel_password'],
                'goto_uri' => '/'];

        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));

        // Check the Header
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $no_follow_location = 0;
        if (function_exists('ini_get')) {
            $open_basedir = ini_get('open_basedir'); // Followlocation does not work if open_basedir is enabled
            if (!empty($open_basedir)) {
                $no_follow_location = 1;
            }
        }

        if (empty($no_follow_location)) {
            // Do not follow redirects
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        }

        // Get response from the server.
        $resp = curl_exec($ch);

        $error = curl_error($ch);
        // Did we login ?
        if ($resp === false) {
            $errorMessage = Language::_('SoftaculousPlugin.remote_curl_error', true, $error);
            $this->Input->setErrors(['login' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        // Get the cpsess and path to frontend theme
        $curl_info = curl_getinfo($ch);

        $parsed = ['path' => ''];
        if (!empty($curl_info['redirect_url'])) {
            $parsed = parse_url($curl_info['redirect_url']);
        } elseif (!empty($curl_info['url'])) {
            $parsed = parse_url($curl_info['url']);
        }

        $path = trim(dirname($parsed['path']));
        $path = rtrim($path[0] == '/' ? $path : '/' . $path, '/');

        curl_close($ch);

        // Did we login ?
        if (empty($path)) {
            $errorMessage = Language::_('SoftaculousPlugin.remote_firewall_error', true);
            $this->Input->setErrors(['login' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        // Make the Login system
        $login = 'https://' . rawurlencode($service_fields['cpanel_username']) . ':'
            . rawurlencode($service_fields['cpanel_password']) . '@' . $meta->host_name . ':2083'
            . $path . '/softaculous/index.live.php';

        $data['softdomain'] = $service_fields['cpanel_domain'];

        // OPTIONAL - By default it will be installed in the /public_html folder
        $data['softdirectory'] = (!empty($config_options['directory']) ? $config_options['directory'] : '');

        $data['admin_username'] = $config_options['admin_name'];
        $data['admin_pass'] = $config_options['admin_pass'];
        $data['admin_email'] = $client->email;

        // List of Scripts
        $scripts = $this->softaculousScripts();
        $ins_script = $config_options['script'];

        // Which Script are we to install ?
        foreach ($scripts as $key => $value) {
            if (trim(strtolower($value['name'])) == trim(strtolower($ins_script))) {
                $sid = $key;
                break;
            }
        }

        // Did we find the Script ?
        if (empty($sid)) {
            $errorMessage = Language::_('SoftaculousPlugin.script_selected_error', true, $ins_script);
            $this->Input->setErrors(['script_id' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        $response = $this->scriptInstallRequest($sid, $login, $data); // Will install the script
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