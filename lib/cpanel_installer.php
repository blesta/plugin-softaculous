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
        $nvpreq = http_build_query($post);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $nvpreq);

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
            // Follow redirects
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        }

        //curl_setopt($ch, CURLOPT_COOKIEJAR, '-');

        // Get response from the server.
        $resp = curl_exec($ch);

        $error = curl_error($ch);
        // Did we login ?
        if ($resp === false) {
            $this->Input->setErrors([
                'login' => [
                    'invalid' => 'Could not login to the remote server. cURL Error : ' . $error
                ]
            ]);
            return;
        }

        // Get the cpsess and path to frontend theme
        $curl_info = curl_getinfo($ch);

        if (!empty($curl_info['redirect_url'])) {
            $parsed = parse_url($curl_info['redirect_url']);
        } else {
            $parsed = parse_url($curl_info['url']);
        }

        $path = trim(dirname($parsed['path']));
        $path = ($path{0} == '/' ? $path : '/' . $path);

        curl_close($ch);

        // Did we login ?
        if (empty($path)) {
            $this->Input->setErrors([
                'login' => [
                    'invalid' => 'Could not determine the location of the Softaculous on the remote server.'
                        . ' There could be a firewall preventing access.'
                ]
            ]);
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
            $this->Input->setErrors([
                'script_id' => [
                    'invalid' => 'Could not determine the script to be installed.'
                    . ' Please make sure the script name is correct. Script Name : ' . $ins_script
                ]
            ]);
            return;
        }

        $res = $this->scriptInstallRequest($sid, $login, $data); // Will install the script
        $res = trim($res);
        if (preg_match('/installed/is', $res)) {
            return true;
        } else {
            $this->Input->setErrors([
                'script_id' => [
                    'invalid' => 'Could not install script: ' . $res
                ]
            ]);
            return false;
        }
    }
}
