<?php
abstract class SoftactulousInstaller
{
    /**
     * @var Monolog\Logger An instance of the logger
     */
    protected $logger;

    /**
     * The installer constructor
     *
     * @param Monolog\Logger $logger An instance of the logger
     */
    public function __construct($logger)
    {
        Loader::loadComponents($this, ['Input']);
        $this->logger = $logger;
    }

    /**
     * Validates informations and runs a softaculous installation script on the given web panel
     *
     * @param stdClass $service An object representing the web panel service to execute a script for
     * @param stdClass $meta The module row meta data for the service
     * @return boolean Whether the script succeeded
     */
    abstract public function install(stdClass $service, stdClass $meta);

    /**
     * Gets a list of scripts available in softaculous
     *
     * @global type $SoftaculousScripts
     * @global type $add_SoftaculousScripts
     * @return array A list of scripts available in softaculous
     */
    protected function softaculousScripts()
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
            $errorMessage = Language::_('SoftaculousPlugin.no_script_list', true);
            $this->Input->setErrors(['no_script_list' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
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
    protected function scriptInstallRequest($sid, $login, array $data)
    {
        @define('SOFTACULOUS', 1);

        $scripts = $this->softaculousScripts();
        if (empty($scripts[$sid])) {
            $errorMessage = Language::_('SoftaculousPlugin.no_script_loaded', true);
            $this->Input->setErrors(['no_script_loaded' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        // Add a Question mark if necessary
        $login .= substr_count($login, '?') < 1 ?  '?' : '&';

        // Login PAGE
        if (in_array($scripts[$sid]['type'], ['js', 'perl', 'java'])) {
            $login .= 'act=js' . $scripts[$sid]['type'];
        } else {
            $login .= 'act=software';
        }

        $login = $login . '&api=json&soft=' . $sid . '&autoinstall=' . rawurlencode(base64_encode(serialize($data)));

        // Set the curl parameters.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $login);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);

        // Is there a Cookie
        if (!empty($this->cookie)) {
            curl_setopt($ch, CURLOPT_COOKIESESSION, true);
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Get response from the server.
        $resp = curl_exec($ch);
        $error = curl_error($ch);
        // Did we reach out to that place ?
        if ($resp === false || $resp === null) {
            $errorMessage = Language::_('SoftaculousPlugin.script_not_installed', true);
            $this->Input->setErrors(['script_not_installed' => ['invalid' => $error]]);
            $this->logger->error($errorMessage);
        }

        curl_close($ch);
        return json_decode($resp);
    }

    /**
     * Send an HTTP request.
     *
     * @param array $post The parameters to include in the request
     * @param string $url Specifies the url to invoke
     * @param string $method Http request method (GET, DELETE, POST)
     * @return string An json formatted string containing the response
     */
    protected function post(array $post, $url, $method = 'GET')
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

        // If we are being redirected, return the CURL info instead of the response
        if (!empty($curlInfo['redirect_url'])
            && !empty($curlInfo['url'])
            && $curlInfo['redirect_url'] != $curlInfo['url']
        ) {
            return json_encode($curlInfo);
        }

        return trim(substr($response, $curlInfo['header_size']));
    }

    /**
     * Parses an HTTP response for cookies and records them for later use
     *
     * @param string $response The string response from CentOS Web Panel
     */
    private function setCookie($response)
    {
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches);

        if (!empty($matches)) {
            $cookies = [];
            foreach ($matches[1] as $item) {
                parse_str($item, $cookie);
                $cookies = array_merge($cookies, $cookie);
            }

            foreach ($cookies as $cookie => $value) {
                $this->cookie = $cookie . '=' . $value;
            }
        }
    }
}
