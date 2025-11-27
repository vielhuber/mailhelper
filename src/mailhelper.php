<?php
namespace vielhuber\mailhelper;

error_reporting(E_ALL & ~E_DEPRECATED);

// Autoloader: Try different paths depending on installation method
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php', // Local development
    __DIR__ . '/../../../autoload.php', // Installed via Composer (vendor/vielhuber/mailhelper/src)
    __DIR__ . '/../../../../autoload.php' // Alternative Composer path
];
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

use PhpMcp\Server\Attributes\McpTool;
use Webklex\PHPIMAP\ClientManager;
use PHPMailer\PHPMailer\PHPMailer;

class mailhelper
{
    public static $config = [];

    /**
     * Fetch emails from a mailbox.
     *
     * @param string $mailbox The email address of the mailbox to fetch from (must be configured in config.json)
     * @param string|null $folder The folder to fetch from (e.g. 'INBOX', 'INBOX/subfolder'). If null, fetches from all folders
     * @param array|null $filter Filter criteria: date_from, date_until, subject, message, to, cc
     * @param int $limit Maximum number of emails to fetch (default: 100)
     * @param string|null $order Sort order: 'asc' (oldest first) or 'desc' (newest first, default)
     * @return array Array of email objects with id, from, to, cc, date, subject
     */
    #[McpTool(name: 'fetch_emails', description: 'Fetch emails from a mailbox with optional filtering and pagination')]
    public static function fetch($mailbox = null, $folder = null, $filter = null, $limit = 100, $order = null)
    {
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

            $query = $folders__value->messages();
            $query->setFetchBody(false);
            $query->setFetchOrder($order_str);
            $query->all();
            $page = 1;
            while (true) {
                $paginator_limit = 10;
                if ($limit !== null && $limit < $paginator_limit) {
                    $paginator_limit = $limit;
                }
                $paginator = $query->paginate($paginator_limit, $page, 'imap_page');

                if ($paginator->count() === 0) {
                    break;
                }

                // convert iterator to array
                $messages = iterator_to_array($paginator);

                // reorder (this is somewhat unexpected behaviour)
                if ($order_str === 'desc') {
                    $messages = array_values(array_reverse($messages));
                }

                foreach ($messages as $messages__value) {
                    if (self::check_filter($filter, $messages__value) === false) {
                        continue;
                    }

                    $mail = self::get_mail_data_basic($messages__value);

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
        return $mails;
    }

    /**
     * Send an email via SMTP.
     *
     * @param string $mailbox The sender email address (must be configured in config.json)
     * @param string $subject The email subject
     * @param string $message The email body (HTML supported)
     * @param string|null $from_name The sender name (overrides config value if set)
     * @param string|array $to Recipient(s): string 'email@example.com' or array [{"name": "John", "email": "john@example.com"}]
     * @param string|array|null $cc CC recipient(s): same format as $to
     * @param string|array|null $bcc BCC recipient(s): same format as $to
     * @param string|array|null $attachments File path(s) or array [{"name": "file.pdf", "file": "/path/to/file.pdf"}]
     * @return bool True on success
     * @throws \Exception If sending fails
     */
    #[McpTool(name: 'send_email', description: 'Send an email with optional attachments, CC, and BCC recipients')]
    public static function send(
        $mailbox = null,
        $subject = null,
        $message = null,
        $from_name = null,
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
            $mail->setFrom($mailbox, $from_name ?? '');
            $mail->SMTPAuth = true;
            $mail->SMTPOptions = [
                'tls' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
            ];
            $mail->CharSet = 'utf-8';
            $mail->isHTML(true);

            foreach (['to' => 'addAddress', 'cc' => 'addCC', 'bcc' => 'addBCC'] as $fields__key => $fields__value) {
                if (!is_array(${$fields__key}) || isset(${$fields__key}['email'])) {
                    ${$fields__key} = [${$fields__key}];
                }
                foreach (${$fields__key} as $recipients__value) {
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
                        if (is_string($attachments__value) && $attachments__value != '') {
                            $attachments__value = self::check_and_format_attachment($attachments__value);
                            $mail->addAttachment($attachments__value);
                        } elseif (is_array($attachments__value)) {
                            if (
                                isset($attachments__value['file']) &&
                                $attachments__value['file'] != '' &&
                                isset($attachments__value['name']) &&
                                $attachments__value['name'] != ''
                            ) {
                                $attachments__value['file'] = self::check_and_format_attachment(
                                    $attachments__value['file']
                                );
                                $mail->addAttachment($attachments__value['file'], $attachments__value['name']);
                            } elseif (
                                isset($attachments__value['file']) &&
                                $attachments__value['file'] != '' &&
                                file_exists($attachments__value['file'])
                            ) {
                                $attachments__value['file'] = self::check_and_format_attachment(
                                    $attachments__value['file']
                                );
                                $mail->addAttachment($attachments__value['file']);
                            }
                        }
                    }
                }
            }
            $mail->send();
            return true;
        } catch (\Exception $e) {
            throw new \Exception(
                'Message could not be sent. (' . $e->getMessage() . ') - Mailer Error: ' . ($mail->ErrorInfo ?? '-')
            );
        }
    }

    /**
     * Get all folders from a mailbox.
     *
     * @param string $mailbox The email address of the mailbox (must be configured in config.json)
     * @return array Array of folder names (e.g. ['INBOX', 'INBOX/subfolder', 'Sent', 'Trash'])
     */
    #[McpTool(name: 'get_folders', description: 'List all available folders in a mailbox')]
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
     * View a specific email with full content and attachments.
     *
     * @param string $mailbox The email address of the mailbox (must be configured in config.json)
     * @param string|null $folder The folder containing the email. If null, searches all folders
     * @param string $id The Message-ID of the email (from fetch response)
     * @return array|null Email data with id, from, to, cc, date, subject, content_html, content_plain, eml, attachments
     * @throws \Exception If email not found
     */
    #[
        McpTool(
            name: 'view_email',
            description: 'Get full email content including HTML body, plain text, and attachments'
        )
    ]
    public static function view($mailbox = null, $folder = null, $id = null)
    {
        self::parse_config();
        self::validate_input('edit', get_defined_vars());
        $settings = self::setup_settings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders = $client->getFolders();
        foreach ($folders as $folders__value) {
            if ($folder !== null && $folders__value->full_name !== $folder) {
                continue;
            }
            $message = $folders__value->messages()->whereMessageId($id)->get()->first();
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }

            $mail = self::get_mail_data_basic($message);

            $mail['eml'] =
                'data:message/rfc822;base64,' .
                base64_encode(json_decode(json_encode($message->getHeader()), true)['raw'] . $message->getRawBody());
            $mail['content_html'] = $message->getHTMLBody();
            $mail['content_plain'] = $message->getTextBody();

            $mail['attachments'] = [];
            $attachments = $message->getAttachments();
            if (!empty($attachments)) {
                foreach ($attachments as $attachments__value) {
                    $mail['attachments'][] = [
                        'name' => $attachments__value->getFilename(),
                        'content' =>
                            'data:' .
                            $attachments__value->getContentType() .
                            ';base64,' .
                            base64_encode($attachments__value->getContent())
                    ];
                }
            }

            // embed images
            $attachments = $message->getAttachments();
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

            return $mail;
        }
        return null;
    }

    /**
     * Edit an email (move, delete, mark as read/unread).
     *
     * @param string $mailbox The email address of the mailbox (must be configured in config.json)
     * @param string|null $folder The folder containing the email. If null, searches all folders
     * @param string $id The Message-ID of the email (from fetch response)
     * @param string|null $move Target folder to move the email to (e.g. 'INBOX/Archive')
     * @param bool|null $delete Set to true to delete the email
     * @param bool|null $read Set to true to mark the email as read
     * @param bool|null $unread Set to true to mark the email as unread
     * @return bool True on success
     * @throws \Exception If email not found or operation fails
     */
    #[McpTool(name: 'edit_email', description: 'Modify an email: move to folder, delete, or change read status')]
    public static function edit(
        $mailbox = null,
        $folder = null,
        $id = null,
        $move = null,
        $delete = null,
        $read = null,
        $unread = null
    ) {
        self::parse_config();
        self::validate_input('edit', get_defined_vars());
        $settings = self::setup_settings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders = $client->getFolders();
        foreach ($folders as $folders__value) {
            if ($folder !== null && $folders__value->full_name !== $folder) {
                continue;
            }
            $message = $folders__value->messages()->whereMessageId($id)->get()->first();
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }
            if ($move !== null) {
                $message->move($move);
            }
            if ($delete === true) {
                $message->delete();
            }
            if ($read === true) {
                $message->setFlag('Seen');
            }
            if ($unread === true) {
                $message->unsetFlag('Seen');
            }
        }
        return true;
    }

    private static function parse_config()
    {
        $configPath = self::get_base_path() . '/config.json';
        if (!file_exists($configPath)) {
            throw new \Exception('Configuration file not found: ' . $configPath);
        }
        $configContent = file_get_contents($configPath);
        self::$config = json_decode($configContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error decoding configuration file: ' . json_last_error_msg());
        }
    }

    private static function get_base_path()
    {
        $path = __DIR__;

        // go up one level (src)
        $path = dirname($path);

        // if we are inside vendor, go up multiple levels
        if (strpos($path, '/vendor/') !== false) {
            $path = dirname(dirname(dirname($path)));
        }

        return $path;
    }

    public static function parse_cli()
    {
        $args = $_SERVER['argv'];

        // parse action
        $action = $args[1] ?? null;
        if (!in_array($action, ['fetch', 'folders', 'send', 'edit', 'view'])) {
            echo "Usage: mailhelper [fetch|send|folders|view|edit] [options]\n";
            die();
        }

        // parse arguments
        $options = [];
        if (count($args) >= 2) {
            for ($i = 2; $i < count($args); $i++) {
                if (strpos($args[$i], '--') === 0) {
                    $key = substr($args[$i], 2);
                    $key = str_replace('-', '_', $key);
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
                    order: $options['order'] ?? null
                );
            }

            if ($action === 'send') {
                $response = mailhelper::send(
                    mailbox: $options['mailbox'] ?? null,
                    subject: $options['subject'] ?? null,
                    message: $options['message'] ?? null,
                    from_name: $options['from_name'] ?? null,
                    to: $to,
                    cc: $cc,
                    bcc: $bcc,
                    attachments: $attachments
                );
            }

            if ($action === 'folders') {
                $response = mailhelper::folders(mailbox: $options['mailbox'] ?? null);
            }

            if ($action === 'view') {
                $response = mailhelper::view(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    id: $options['id'] ?? null
                );
            }

            if ($action === 'edit') {
                $response = mailhelper::edit(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    id: $options['id'] ?? null,
                    move: $options['move'] ?? null,
                    delete: isset($options['delete']) ? (bool) $options['delete'] : null,
                    read: isset($options['read']) ? (bool) $options['read'] : null,
                    unread: isset($options['unread']) ? (bool) $options['unread'] : null
                );
            }

            if ($response !== null) {
                $response = self::pretty_print($response);
                echo $response;
                die();
            }
        } catch (\Exception $e) {
            echo '⛔ ';
            echo $e->getMessage() . "\n";
            die();
        }
    }

    public static function is_cli()
    {
        return php_sapi_name() === 'cli' && isset($_SERVER['argv']) && basename($_SERVER['argv'][0]) !== 'phpunit';
    }

    private static function pretty_print($data, $indent = 0)
    {
        if ($data === true || $data === '1' || $data === 1) {
            return '✅' . "\n";
        }
        if ($data === false || $data === '0' || $data === 0) {
            return '⛔' . "\n";
        }
        $output = '';
        $prefix = str_repeat('  ', $indent);

        if (is_array($data)) {
            if (empty($data)) {
                $output .= $prefix . '[]' . "\n";
            } else {
                $is_assoc = array_keys($data) !== range(0, count($data) - 1);
                foreach ($data as $key => $value) {
                    if (is_array($value)) {
                        if ($is_assoc) {
                            $output .= $prefix . "\033[1;36m" . $key . ":\033[0m\n";
                        } else {
                            $output .= $prefix . "\033[1;33m[" . $key . "]\033[0m\n";
                        }
                        $output .= self::pretty_print($value, $indent + 1);
                    } else {
                        if ($is_assoc) {
                            $output .=
                                $prefix . "\033[1;36m" . $key . ":\033[0m " . self::pretty_print_value($value) . "\n";
                        } else {
                            $output .= $prefix . '- ' . self::pretty_print_value($value) . "\n";
                        }
                    }
                }
            }
        } else {
            $output .= $prefix . self::pretty_print_value($data) . "\n";
        }

        return $output;
    }

    private static function pretty_print_value($value)
    {
        if (is_array($value) && empty($value)) {
            return '[]';
        }
        if ($value === null) {
            return '[NULL]';
        }
        if ($value === true) {
            return '[TRUE]';
        }
        if ($value === false) {
            return '[FALSE]';
        }
        if ($value === '') {
            return '""';
        }
        if (!is_string($value)) {
            return $value;
        }
        // replace newlines
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $maxLength = 50;
        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength) . '...';
        }
        return $value;
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
        if ($action === 'edit') {
            if (!($args['id'] ?? null)) {
                throw new \Exception('Missing id.');
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

    private static function get_mail_data_basic($message)
    {
        $mail = [];
        $mail['id'] = $message->getMessageId()->toString();

        foreach (['from' => 'getFrom', 'to' => 'getTo', 'cc' => 'getCc'] as $fields__key => $fields__value) {
            $mail[$fields__key] = [];
            $addresses = $message->$fields__value()->toArray();
            foreach ($addresses as $addresses__value) {
                $mail[$fields__key][] = [
                    'name' => $addresses__value->personal ?? '',
                    'email' => $addresses__value->mail ?? ''
                ];
            }
        }

        $mail['date'] = $message->getDate()->toDate()->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s');

        $subject = @$message->getSubject()[0];
        $subject = trim($subject);
        $subject = preg_replace("/\r\n|\r|\n/", '', trim(@$message->getSubject()[0]));
        if (mb_detect_encoding($subject, 'UTF-8, ISO-8859-1') !== 'UTF-8') {
            $subject = utf8_encode($subject);
        }
        $mail['subject'] = $subject;
        return $mail;
    }

    private static function check_and_format_attachment($attachment)
    {
        if (!file_exists($attachment)) {
            $attachment = self::get_base_path() . '/' . ltrim($attachment, '/');
        }
        if (!file_exists($attachment)) {
            throw new \Exception('Attachment file not found: ' . $attachment);
        }
        return $attachment;
    }
}

if (mailhelper::is_cli()) {
    mailhelper::parse_cli();
}
