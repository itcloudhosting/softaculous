<?php
include_once dirname(__FILE__) . DS . 'softaculous_installer.php';

class CentoswebpanelInstaller extends SoftactulousInstaller
{
    /**
     * Validates informations and runs a softaculous installation script on CentOS Web Panel
     *
     * @param stdClass $service An object representing the CentOS Web Panel service to execute a script for
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
        $autoLoginData = [
            'action' => 'list',
            'key' => isset($meta->api_key) ? $meta->api_key : '',
            'user' => isset($serviceFields['centoswebpanel_username']) ? $serviceFields['centoswebpanel_username'] : '',
            'module' => 'softaculous'
        ];
        $hostName = isset($meta->host_name) ? $meta->host_name : '';
        $port = isset($meta->port) ? $meta->port : '';
        $autoLoginUrl = 'https://' . $hostName . ':' . $port . '/v1/user_session';
        $autoLoginRaw = $this->post($autoLoginData, $autoLoginUrl, 'POST');
        $autoLoginResponse = json_decode($autoLoginRaw);
        if ($autoLoginResponse == null || !isset($autoLoginResponse->msj->details[0]->token)) {
            return;
        }
        $token = $autoLoginResponse->msj->details[0]->token;

        if (strtolower($autoLoginResponse->status) == 'error') {
            $errorMessage = Language::_('SoftaculousPlugin.remote_error_message', true, $autoLoginResponse->msj);
            $this->Input->setErrors(['login' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        $loginData = ['username' => $serviceFields['centoswebpanel_username'], 'token' => $token];
        $loginRaw = $this->post(
            $loginData,
            'https://' . $hostName . ':2083/' . $serviceFields['centoswebpanel_username'] . '/',
            'POST'
        );
        $loginResponse = json_decode($loginRaw);
        if ($loginResponse == null) {
            $errorMessage = Language::_('SoftaculousPlugin.remote_error', true);
            $this->Input->setErrors(['login' => ['invalid' => $errorMessage]]);
            $this->logger->error($errorMessage);
            return;
        }

        // Make the Login system
        $data = [
            'softdomain' => (!empty($serviceFields['centoswebpanel_domain'])
                ? $serviceFields['centoswebpanel_domain']
                : ''),
            // OPTIONAL - By default it will be installed in the /public_html folder
            'softdirectory' => (!empty($configOptions['directory']) ? $configOptions['directory'] : ''),
            'admin_username' => isset($configOptions['admin_name']) ? $configOptions['admin_name'] : '',
            'admin_pass' => isset($configOptions['admin_pass']) ? $configOptions['admin_pass'] : '',
            'admin_email' => $client->email
        ];

        // List of Scripts
        $scripts = $this->softaculousScripts();
        $installationScript = isset($configOptions['script']) ? $configOptions['script'] : '';

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
        $response = $this->scriptInstallRequest(
            $sid,
            isset($loginResponse->redirect_url) ? $loginResponse->redirect_url : '',
            $data
        );

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

    /**
     * Send a request to the CentOS WebPanel.
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
     * Parses a response from CentOS Web Panel for cookies and records them for later use
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