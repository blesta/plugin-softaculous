<?php
class SoftaculousPlugin extends Plugin
{
    public function __construct()
    {
        Language::loadLang('softaculous', null, dirname(__FILE__) . DS . 'language' . DS);
        Loader::loadComponents($this, ['Input']);

        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    public function install($plugin_id)
    {
        return $plugin_id;
    }

    public function getEvents()
    {
        return [
            [
                'event' => 'Services.add',
                'callback' => ['this', 'softAutoInstall']
            ],
            [
                'event' => 'Services.edit',
                'callback' => ['this', 'softAutoInstall']
            ]
            // Add multiple events here
        ];
    }

    public function softAutoInstall($event)
    {
        if (!isset($this->ModuleManager)) {
            Loader::loadModels($this, ['ModuleManager']);
        }
        if (!isset($this->Services)) {
            Loader::loadModels($this, ['Services']);
        }

        $par = $event->getParams();

        // Get module info
        $module_info = $this->getModuleClassByPricingId($par['vars']['pricing_id']);

        // Make sure the service is being activated at this time
        $service_activated = $par['vars']['status'] == 'active'
            && ($event->getName() == 'Services.add'
                || ($event->getName() == 'Services.edit'
                    && in_array($par['old_service']->status, ['pending', 'in_review'])
                )
            );

        // This plugin only supports the follwing modules: cPanel
        $accepted_modules = ['cpanel'];
        if ($service_activated && $module_info && in_array($module_info->class, $accepted_modules)) {
            // Fetch necessary data
            $service = $this->Services->get($par['service_id']);
            $module_row = $this->ModuleManager->getRow($service->module_row_id);

            if ($module_info->class == 'cpanel') {
                $this->softInstallCpanel($service, $module_row->meta->host_name);
            }
        }
    }

    /**
     * Returns info regarding the module belonging to the given $package_pricing_id
     *
     * @param int $package_pricing_id The package pricing ID to fetch the module of
     * @return mixed A stdClass object containing module info and the package
     *  ID belonging to the given $package_pricing_id, false if no such module exists
     */
    private function getModuleClassByPricingId($package_pricing_id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        return $this->Record->select(['modules.*', 'packages.id' => 'package_id'])->from('package_pricing')->
            innerJoin('packages', 'packages.id', '=', 'package_pricing.package_id', false)->
            innerJoin('modules', 'modules.id', '=', 'packages.module_id', false)->
            where('package_pricing.id', '=', $package_pricing_id)->
            fetch();
    }

    /**
     * Validates informations and runs a softaculous installation script on cPanel
     *
     * @param stdClass $service An object representing the cPanel service to execute a script for
     * @param string $host The host name of the cPanel installation
     * @return boolean Whether the script succeeded
     */
    public function softInstallCpanel($service, $host)
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
        curl_setopt($ch, CURLOPT_URL, 'https://' . $host . ':2083/login/');
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
            . rawurlencode($service_fields['cpanel_password']) . '@' . $host . ':2083'
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

    /**
     * Gets a list of scripts available in softaculous
     *
     * @global type $SoftaculousScripts
     * @global type $add_SoftaculousScripts
     * @return array A list of scripts available in softaculous
     */
    private function softaculousScripts()
    {
        global $SoftaculousScripts, $add_SoftaculousScripts;

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.softaculous.com/scripts.php?in=serialize');

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($ch, CURLOPT_SSL_CIPHER_LIST, 'RC4-SHA:RC4-MD5');
        // This is becuase some servers cannot access https without this

        // Get response from the server
        $resp = curl_exec($ch);
        $scripts = unserialize($resp);
        $error = curl_error($ch);

        if (!is_array($scripts)) {
            $this->Input->setErrors([
                'no_script_list' => [
                    'invalid' => 'Could not download list of scripts. ' . $error
                ]
            ]);
        }

        $SoftaculousScripts = $scripts;

        if (is_array($add_SoftaculousScripts)) {
            foreach ($add_SoftaculousScripts as $k => $v) {
                $SoftaculousScripts[$k] = $v;
            }
        }

        return $SoftaculousScripts;
    }

    /**
     * Sends the installation request to cPanel
     *
     * @param int $sid The id of the script to use
     * @param string $login The login url/credentials to use
     * @param array $data The data to send to cPanel
     * @return string 'installed' on success, an error message otherwise
     */
    private function scriptInstallRequest($sid, $login, $data)
    {
        @define('SOFTACULOUS', 1);

        $scripts = $this->softaculousScripts();

        if (empty($scripts[$sid])) {
            $this->Input->setErrors([
                'no_script_loaded' => [
                    'invalid' => 'List of scripts not loaded. Aborting Installation attempt!'
                ]
            ]);
            return;
        }

        // Add a Question mark if necessary
        if (substr_count($login, '?') < 1) {
            $login = $login . '?';
        }

        // Login PAGE
        if ($scripts[$sid]['type'] == 'js') {
            $login = $login . 'act=js&soft=' . $sid;
        } elseif ($scripts[$sid]['type'] == 'perl') {
            $login = $login . 'act=perl&soft=' . $sid;
        } elseif ($scripts[$sid]['type'] == 'java') {
            $login = $login . 'act=java&soft=' . $sid;
        } else {
            $login = $login . 'act=software&soft=' . $sid;
        }

        $login = $login . '&autoinstall=' . rawurlencode(base64_encode(serialize($data)));

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $login);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);

        // Is there a Cookie
        if (!empty($cookie)) {
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Get response from the server.
        $resp = curl_exec($ch);
        $error = curl_error($ch);
        // Did we reach out to that place ?
        if ($resp === false) {
            $this->Input->setErrors([
                'script_not_installed' => [
                    'invalid' => 'Installation not completed. cURL Error : ' . $error
                ]
            ]);
        }

        curl_close($ch);

        // Was there any error ?
        if ($resp != 'installed') {
            return $resp;
        }

        return 'installed';
    }
}
