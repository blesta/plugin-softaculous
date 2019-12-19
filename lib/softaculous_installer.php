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
     * Send an HTTP request.
     *
     * @param array $post The parameters to include in the request
     * @param string $url Specifies the url to invoke
     * @param string $method Http request method (GET, DELETE, POST)
     * @return string An json formatted string containing the response
     */
    protected function makeRequest(array $post, $url, $method = 'GET')
    {
        $ch = curl_init();

        // Set the request method and parameters
        switch (strtoupper($method)) {
            case 'GET':
            case 'DELETE':
                $url .= empty($post) ? '' : (substr_count($url, '?') < 1 ? '?' : '&') . http_build_query($post);
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
        if (!empty($this->cookie)) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }

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
            return (object) $curlInfo;
        }

        return json_decode(trim(substr($response, $curlInfo['header_size'])));
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

    /**
     * Runs an installation script through Softaculous
     *
     * @param string $scriptDomain The domain on which to install the script
     * @param string $scriptEmail The admin email for the script
     * @param string $panelUrl The url of the panel to access Softaculous on
     * @param array $configOptions A list of config options for the service associated with this domain
     * @return boolean True if installation succeeded, false otherwise
     */
    protected function installScript($scriptDomain, $scriptEmail, $panelUrl, array $configOptions)
    {
        // List of Scripts
        $scripts = $this->makeRequest(
            ['act' => 'home', 'api' => 'json'],
            $panelUrl,
            'GET'
        );
        $installationScript = isset($configOptions['script']) ? $configOptions['script'] : '';

        // Which Script are we to install ?
        $script = null;
        if (isset($scripts->iscripts)) {
            foreach ($scripts->iscripts as $key => $value) {
                if (trim(strtolower($value->name)) == trim(strtolower($installationScript))) {
                    $sid = $key;
                    $script = $value;
                    break;
                }
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
            'softdomain' => $scriptDomain,
            // OPTIONAL - By default it will be installed in the /public_html folder
            'softdirectory' => (!empty($configOptions['directory']) ? $configOptions['directory'] : ''),
            'admin_username' => isset($configOptions['admin_name']) ? $configOptions['admin_name'] : '',
            'admin_pass' => isset($configOptions['admin_pass']) ? $configOptions['admin_pass'] : '',
            'admin_email' => $scriptEmail
        ];
        $params = [
            'act' => in_array($script->type, ['js', 'perl', 'java']) ? $script->type : 'software',
            'api' => 'json',
            'soft' => $sid,
            'autoinstall' => rawurlencode(base64_encode(serialize($data)))
        ];
        $url = $panelUrl . (substr_count($panelUrl, '?') < 1 ?  '?' : '&') . http_build_query($params);
        $response = $this->makeRequest($params, $url, 'POST');

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
