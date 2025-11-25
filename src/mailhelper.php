#!/usr/bin/env php
<?php namespace vielhuber\mailhelper;

error_reporting(E_ALL & ~E_DEPRECATED);

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use PhpMcp\Server\Attributes\McpTool;
use Webklex\PHPIMAP\ClientManager;
use PHPMailer\PHPMailer\PHPMailer;

class mailhelper
{
    public static $config = [];

    public static function parse_config()
    {
        $configPath = __DIR__ . '/../config.json';
        if (!file_exists($configPath)) {
            throw new \Exception('Configuration file not found: ' . $configPath);
        }
        $configContent = file_get_contents($configPath);
        self::$config = json_decode($configContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error decoding configuration file: ' . json_last_error_msg());
        }
    }

    private static function is_cli()
    {
        return php_sapi_name() === 'cli' && isset($_SERVER['argv']) && basename($_SERVER['argv'][0]) !== 'phpunit';
    }

    public static function cli()
    {
        if (self::is_cli()) {
            $args = $_SERVER['argv'];

            // parse action
            $action = $args[1] ?? null;
            if (!in_array($action, ['fetch', 'folders', 'send'])) {
                echo "Usage: mailhelper [fetch|folders|send] [options]\n";
                die();
            }

            // parse arguments
            $options = [];
            if (count($args) >= 2) {
                for ($i = 2; $i < count($args); $i++) {
                    if (strpos($args[$i], '--') === 0) {
                        $key = substr($args[$i], 2);
                        $value = $args[$i + 1] ?? true;
                        if (is_string($value) && strpos($value, '--') === 0) {
                            $value = true;
                        } elseif ($value !== true) {
                            $i++;
                        }
                        $options[$key] = $value;
                    }
                }
            }

            // parse filter
            $filter = [];
            if (isset($options['filter-date-from'])) {
                $filter['date_from'] = $options['filter-date-from'];
            }
            if (isset($options['filter-date-until'])) {
                $filter['date_until'] = $options['filter-date-until'];
            }
            if (isset($options['filter-subject'])) {
                $filter['subject'] = $options['filter-subject'];
            }
            if (isset($options['filter-message'])) {
                $filter['message'] = $options['filter-message'];
            }
            if (isset($options['filter-to'])) {
                $filter['to'] = $options['filter-to'];
            }
            if (isset($options['filter-cc'])) {
                $filter['cc'] = $options['filter-cc'];
            }
            if (isset($options['filter-bcc'])) {
                $filter['bcc'] = $options['filter-bcc'];
            }

            // parse json
            $to = isset($options['to']) ? json_decode($options['to'], true) ?? $options['to'] : null;
            $cc = isset($options['cc']) ? json_decode($options['cc'], true) ?? $options['cc'] : null;
            $bcc = isset($options['bcc']) ? json_decode($options['bcc'], true) ?? $options['bcc'] : null;
            $attachments = isset($options['attachments'])
                ? json_decode($options['attachments'], true) ?? $options['attachments']
                : null;

            try {
                $response = null;
                if ($action === 'fetch') {
                    $response = mailhelper::fetch(
                        mailbox: $options['mailbox'] ?? null,
                        folder: $options['folder'] ?? null,
                        filter: !empty($filter) ? $filter : null,
                        limit: isset($options['limit']) ? (int) $options['limit'] : 100,
                        order: $options['order'] ?? null,
                        with_attachments: $options['with-attachments'] ?? false
                    );
                }

                if ($action === 'folders') {
                    $response = mailhelper::folders(mailbox: $options['mailbox'] ?? null);
                }

                if ($action === 'send') {
                    $response = mailhelper::send(
                        mailbox: $options['mailbox'] ?? null,
                        subject: $options['subject'] ?? null,
                        message: $options['message'] ?? null,
                        to: $to,
                        cc: $cc,
                        bcc: $bcc,
                        attachments: $attachments
                    );
                }
                if ($response !== null) {
                    self::output_recursively($response);
                    die();
                }
            } catch (\Exception $e) {
                echo $e->getMessage() . "\n";
                die();
            }
        }
    }

    private static function output_recursively($data, $key = null, $level = 0)
    {
        if (is_array($data)) {
            foreach ($data as $data__key => $data__value) {
                self::output_recursively($data__value, $data__key, $level + 1);
                if ($level === 0) {
                    echo "\n";
                }
            }
        } else {
            if ($key !== null) {
                echo $key . ' ::: ';
            }
            if (is_bool($data)) {
                if ($data === true) {
                    echo '✅';
                }
                if ($data === false) {
                    echo '⛔';
                }
            }
            if (is_string($data)) {
                $data = str_replace("\r\n", ' ', $data);
                if (mb_strlen($data) > 30) {
                    echo mb_substr($data, 0, 30) . '...';
                } else {
                    echo $data;
                }
            }
            echo "\n";
        }
    }

    private static function check_filter($filter, $message)
    {
        if ($filter !== null && !empty($filter)) {
            if ($filter['date_from'] ?? null) {
                $date_from = new \DateTime($filter['date_from']);
                if ($message->getDate()->toDate() <= $date_from) {
                    return false;
                }
            }
            if ($filter['date_until'] ?? null) {
                $date_until = new \DateTime($filter['date_until']);
                if ($message->getDate()->toDate() >= $date_until) {
                    return false;
                }
            }
            if ($filter['subject'] ?? null) {
                if (stripos($message->getSubject()[0], $filter['subject']) === false) {
                    return false;
                }
            }
            if ($filter['message'] ?? null) {
                if (
                    stripos($message->getTextBody(), $filter['message']) === false &&
                    stripos($message->getHTMLBody(), $filter['message']) === false
                ) {
                    return false;
                }
            }
            if ($filter['to'] ?? null) {
                $found = false;
                foreach ($message->getTo() as $to_address) {
                    if (stripos($to_address->mail, $filter['to']) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
            if ($filter['cc'] ?? null) {
                $found = false;
                foreach ($message->getCc() as $to_address) {
                    if (stripos($to_address->mail, $filter['cc']) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
            if ($filter['bcc'] ?? null) {
                $found = false;
                foreach ($message->getBcc() as $to_address) {
                    if (stripos($to_address->mail, $filter['bcc']) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return false;
                }
            }
        }
        return true;
    }

    private static function progress($done, $total, $info = '', $width = 75, $char = '=')
    {
        $perc = round(($done * 100) / $total);
        $bar = round(($width * $perc) / 100);
        echo sprintf(
            "%s[%s%s] %s\r",
            $info != '' ? $info . ' ' : '',
            str_repeat($char, $bar) . ($perc < 100 ? '>' : ''),
            $perc == 100 ? $char : str_repeat(' ', $width - $bar),
            str_pad($perc, 3, ' ', STR_PAD_LEFT) . '%'
        );
    }

    /**
     * Fetch emails.
     *
     * @param int $a The first number
     * @param int $b The second number
     * @return int The sum of the two numbers
     */
    #[McpTool(name: 'fetch_emails')]
    public static function fetch(
        $mailbox = null,
        $folder = null,
        $filter = null,
        $limit = 100,
        $order = null,
        $with_attachments = null
    ) {
        self::parse_config();
        self::validate_input('fetch', get_defined_vars());
        $settings = self::setup_settings($mailbox);

        $mails = [];

        $order_str =
            $order !== null && in_array(mb_strtolower($order), ['asc', 'desc']) ? mb_strtolower($order) : 'desc';

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders = $client->getFolders();
        foreach ($folders as $folders__value) {
            if ($folder !== null && $folders__value->full_name !== $folder) {
                continue;
            }

            $query = $folders__value->messages()->setFetchOrder($order_str)->all();
            $page = 0;
            while (true) {
                $paginator = $query->paginate(20, $page, 'imap_page');

                if ($paginator->count() === 0) {
                    break;
                }

                foreach ($paginator as $messages__value) {
                    if (self::check_filter($filter, $messages__value) === false) {
                        continue;
                    }

                    $mail = [];
                    $mail['id'] = $messages__value->getMessageId()[0];
                    $mail['from_name'] = $messages__value->getFrom()[0]->personal;
                    $mail['from_email'] = $messages__value->getFrom()[0]->mail;
                    $mail['to'] = $messages__value->getTo()[0]->mail;
                    $mail['date'] = $messages__value
                        ->getDate()
                        ->toDate()
                        ->setTimezone(date_default_timezone_get())
                        ->format('Y-m-d H:i:s');

                    $subject = @$messages__value->getSubject()[0];
                    $subject = trim($subject);
                    $subject = preg_replace("/\r\n|\r|\n/", '', trim(@$messages__value->getSubject()[0]));
                    if (mb_detect_encoding($subject, 'UTF-8, ISO-8859-1') !== 'UTF-8') {
                        $subject = utf8_encode($subject);
                    }
                    $mail['subject'] = $subject;

                    $mail['eml'] =
                        json_decode(json_encode($messages__value->getHeader()), true)['raw'] .
                        $messages__value->getRawBody();
                    $mail['content_html'] = $messages__value->getHTMLBody();
                    $mail['content_plain'] = $messages__value->getTextBody();

                    if ($with_attachments === true) {
                        $mail['attachments'] = $messages__value->getAttachments();
                    }

                    // embed images
                    $attachments = $messages__value->getAttachments();
                    foreach ($attachments as $attachments__value) {
                        $mail['content_html'] = str_replace(
                            'cid:' . $attachments__value->getId(),
                            'data:' .
                                $attachments__value->getMimeType() .
                                ';base64,' .
                                base64_encode($attachments__value->getContent()),
                            $mail['content_html']
                        );
                    }

                    // methods
                    //$messages__value->setFlag('Seen');
                    //$messages__value->unsetFlag('Seen');
                    //$messages__value->move('INBOX/ARCHIV');

                    $mails[] = $mail;
                    if ($limit !== null) {
                        if (self::is_cli()) {
                            self::progress(count($mails), $limit, 'Fetching emails...');
                        }
                        if (count($mails) >= $limit) {
                            break 3;
                        }
                    }
                }

                $page++;
            }
        }
        if (self::is_cli()) {
            echo PHP_EOL;
        }
        // reorder (this is somewhat unexpected behaviour)
        if ($order_str === 'desc') {
            $mails = array_values(array_reverse($mails));
        }
        return $mails;
    }

    private static function validate_input($action, $args)
    {
        if (!($args['mailbox'] ?? null)) {
            throw new \Exception('Missing mailbox.');
        }
        if (!isset(self::$config[$args['mailbox']])) {
            throw new \Exception('Mailbox not found in configuration: ' . $args['mailbox']);
        }
        if (!isset(self::$config[$args['mailbox']]['imap'])) {
            throw new \Exception('IMAP configuration not found for mailbox: ' . $args['mailbox']);
        }
        if ($action === 'fetch') {
        }
        if ($action === 'send') {
            if (!($args['subject'] ?? null)) {
                throw new \Exception('Missing subject.');
            }
            if (!($args['message'] ?? null)) {
                throw new \Exception('Missing message.');
            }
            if (!($args['to'] ?? null)) {
                throw new \Exception('Missing to.');
            }
        }
    }

    private static function setup_settings($mailbox)
    {
        $settings = [];
        if (self::$config[$mailbox]['tenant_id'] ?? null) {
            $ch = curl_init();
            curl_setopt(
                $ch,
                CURLOPT_URL,
                'https://login.microsoftonline.com/' . self::$config[$mailbox]['tenant_id'] . '/oauth2/v2.0/token'
            );
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                http_build_query([
                    'client_id' => self::$config[$mailbox]['client_id'],
                    'client_secret' => self::$config[$mailbox]['client_secret'],
                    'scope' => 'https://outlook.office365.com/.default',
                    'grant_type' => 'client_credentials'
                ])
            );
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $curl_result = curl_exec($ch);
            if (empty($curl_result)) {
                throw new \Exception('Missing results.');
            }
            $curl_result = json_decode($curl_result);
            if (empty($curl_result)) {
                throw new \Exception('Error decoding json result.');
            }
            if (!isset($curl_result->access_token)) {
                throw new \Exception('Missing access token from result.');
            }
            $access_token = $curl_result->access_token;
            $settings = [
                'protocol' => 'imap',
                'host' => self::$config[$mailbox]['imap']['host'] ?? null,
                'port' => self::$config[$mailbox]['imap']['port'] ?? null,
                'username' => self::$config[$mailbox]['imap']['username'] ?? null,
                'password' => $access_token,
                'authentication' => 'oauth',
                'encryption' => self::$config[$mailbox]['imap']['encryption'] ?? null,
                'validate_cert' => false
            ];
        } else {
            $settings = [
                'protocol' => 'imap',
                'host' => self::$config[$mailbox]['imap']['host'] ?? null,
                'port' => self::$config[$mailbox]['imap']['port'] ?? null,
                'username' => self::$config[$mailbox]['imap']['username'] ?? null,
                'password' => self::$config[$mailbox]['imap']['password'] ?? null,
                'authentication' => null,
                'encryption' => self::$config[$mailbox]['imap']['encryption'] ?? null,
                'validate_cert' => false
            ];
        }
        return $settings;
    }

    /**
     * Get folders.
     *
     * @param int $a The first number
     * @param int $b The second number
     * @return int The sum of the two numbers
     */
    #[McpTool(name: 'get_folders')]
    public static function folders($mailbox = null)
    {
        self::parse_config();
        self::validate_input('folders', get_defined_vars());
        $settings = self::setup_settings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders_raw = $client->getFolders();
        $folders = [];
        foreach ($folders_raw as $folders_raw__value) {
            $folders[] = $folders_raw__value->full_name;
        }
        return $folders;
    }

    /**
     * Send email.
     *
     * @param int $a The first number
     * @param int $b The second number
     * @return int The sum of the two numbers
     */
    #[McpTool(name: 'send_email')]
    public static function send(
        $mailbox = null,
        $subject = null,
        $message = null,
        $to = null,
        $cc = null,
        $bcc = null,
        $attachments = null
    ) {
        self::parse_config();
        self::validate_input('send', get_defined_vars());

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = self::$config[$mailbox]['smtp']['host'] ?? null;
            $mail->Port = self::$config[$mailbox]['smtp']['port'] ?? null;
            $mail->Username = self::$config[$mailbox]['smtp']['username'] ?? null;
            $mail->Password = self::$config[$mailbox]['smtp']['password'] ?? null;
            $mail->SMTPSecure = self::$config[$mailbox]['smtp']['encryption'] ?? null;
            $mail->setFrom($mailbox, self::$config[$mailbox]['smtp']['from_name'] ?? '');
            $mail->SMTPAuth = true;
            $mail->SMTPOptions = [
                'tls' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
            ];
            $mail->CharSet = 'utf-8';
            $mail->isHTML(true);

            foreach (['to' => 'addAddress', 'cc' => 'addCC', 'bcc' => 'addBCC'] as $fields__key => $fields__value) {
                if (!is_array(${$fields__key}) || isset(${$fields__key}['email'])) {
                    $recipients = [${$fields__key}];
                }
                foreach ($recipients as $recipients__value) {
                    if (is_string($recipients__value) && $recipients__value != '') {
                        $mail->$fields__value($recipients__value);
                    } elseif (is_array($recipients__value)) {
                        if (
                            isset($recipients__value['email']) &&
                            $recipients__value['email'] != '' &&
                            isset($recipients__value['name']) &&
                            $recipients__value['name'] != ''
                        ) {
                            $mail->$fields__value($recipients__value['email'], $recipients__value['name']);
                        } elseif (isset($recipients__value['email']) && $recipients__value['email'] != '') {
                            $mail->$fields__value($recipients__value['email']);
                        }
                    }
                }
            }

            // embed images (base64 and relative urls to cid)
            $images = [];
            preg_match_all('/src="([^"]*)"/i', $message, $images);
            $images = $images[1];
            $images = array_unique($images);
            foreach ($images as $images__value) {
                if (strpos($images__value, 'cid:') === false && strpos($images__value, 'http') === false) {
                    $image_cid = md5($images__value);

                    $image_extension = $images__value;
                    if (strpos($images__value, 'base64,') !== false) {
                        if (strpos($images__value, 'image/png') !== false) {
                            $image_extension = 'png';
                        } else {
                            $image_extension = 'jpg';
                        }
                    } else {
                        $image_extension = explode('.', $image_extension);
                        $image_extension = $image_extension[count($image_extension) - 1];
                    }

                    $image_baseurl = $_SERVER['DOCUMENT_ROOT']; // modify this if needed

                    $image_tmp_path = sys_get_temp_dir() . '/' . md5(uniqid()) . '.' . $image_extension;

                    // base64
                    if (strpos($images__value, 'base64,') !== false) {
                        file_put_contents(
                            $image_tmp_path,
                            base64_decode(
                                trim(substr($images__value, strpos($images__value, 'base64,') + strlen('base64')))
                            )
                        );
                        $message = str_replace($images__value, 'cid:' . $image_cid, $message);
                        $mail->addEmbeddedImage($image_tmp_path, $image_cid);
                    }

                    // relative paths
                    elseif (file_exists($image_baseurl . '/' . $images__value)) {
                        $message = str_replace($images__value, 'cid:' . $image_cid, $message);
                        $mail->addEmbeddedImage($image_baseurl . '/' . $images__value, $image_cid);
                    }
                }
            }

            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\r\n", $message));
            if ($attachments !== null) {
                if (!is_array($attachments) || isset($attachments['file'])) {
                    $attachments = [$attachments];
                }
                if (!empty($attachments)) {
                    foreach ($attachments as $attachments__value) {
                        if (
                            is_string($attachments__value) &&
                            $attachments__value != '' &&
                            file_exists($attachments__value)
                        ) {
                            $mail->addAttachment($attachments__value);
                        } elseif (is_array($attachments__value)) {
                            if (
                                isset($attachments__value['file']) &&
                                $attachments__value['file'] != '' &&
                                isset($attachments__value['name']) &&
                                $attachments__value['name'] != '' &&
                                file_exists($attachments__value['file'])
                            ) {
                                $mail->addAttachment($attachments__value['file'], $attachments__value['name']);
                            } elseif (
                                isset($attachments__value['file']) &&
                                $attachments__value['file'] != '' &&
                                file_exists($attachments__value['file'])
                            ) {
                                $mail->addAttachment($attachments__value['file']);
                            }
                        }
                    }
                }
            }
            $mail->send();
            return true;
        } catch (\Exception $e) {
            throw new \Exception('Message could not be sent. Mailer Error: ' . $mail->ErrorInfo);
        }
    }
}

mailhelper::cli();
