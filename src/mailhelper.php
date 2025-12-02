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
    public static function fetchMails($mailbox = null, $folder = null, $filter = null, $limit = 100, $order = null)
    {
        self::parseConfig();
        self::validateInput('fetchMails', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $mails = [];

        $order_str =
            $order !== null && in_array(mb_strtolower($order), ['asc', 'desc']) ? mb_strtolower($order) : 'desc';

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders = $client->getFolders(false);
        foreach ($folders as $folders__value) {
            if ($folder !== null) {
                if (is_array($folder) && !in_array($folders__value->full_name, $folder)) {
                    continue;
                }
                if (
                    is_string($folder) &&
                    $folders__value->full_name !== $folder &&
                    self::decodeImapUtf7($folders__value->full_name) !== $folder
                ) {
                    continue;
                }
            }

            $query = $folders__value->query();
            $query->all();
            $query->leaveUnread();
            $query->setFetchBody(false);

            // this does not work server sided
            // we therefore have to fetch all mails and limit & sort them by hand
            $query->setFetchOrder($order_str);

            $page = 1;
            while (true) {
                $paginator_limit = 10;
                try {
                    $paginator = @$query->paginate($paginator_limit, $page, 'imap_page');
                } catch (\Throwable $e) {
                    break;
                }
                if ($paginator->count() === 0) {
                    break;
                }

                // determine full count
                $full_count = $paginator->total();

                // convert iterator to array
                $messages = iterator_to_array($paginator);

                foreach ($messages as $messages__value) {
                    if (self::checkFilter($filter, $messages__value) === false) {
                        continue;
                    }
                    $mail = self::getMailDataBasic($messages__value);
                    $mails[] = $mail;
                    if (self::isCli()) {
                        self::progress(count($mails), $full_count, 'Fetching emails...');
                    }
                }
                $page++;
            }
        }

        // apply sort afterwards
        usort($mails, function ($a, $b) use ($order_str) {
            if ($order_str === 'asc') {
                return strtotime($a->date) <=> strtotime($b->date);
            } else {
                return strtotime($b->date) <=> strtotime($a->date);
            }
        });

        // apply limit afterwards
        if ($limit !== null) {
            if (count($mails) > $limit) {
                $mails = array_slice($mails, 0, $limit);
            }
        }

        if (self::isCli()) {
            echo PHP_EOL;
        }

        $client->disconnect();
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
    public static function sendMail(
        $mailbox = null,
        $subject = null,
        $content = null,
        $from_name = null,
        $to = null,
        $cc = null,
        $bcc = null,
        $attachments = null
    ) {
        self::parseConfig();
        self::validateInput('sendMail', get_defined_vars());

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
            preg_match_all('/src="([^"]*)"/i', $content, $images);
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
                        $content = str_replace($images__value, 'cid:' . $image_cid, $content);
                        $mail->addEmbeddedImage($image_tmp_path, $image_cid);
                    }

                    // relative paths
                    elseif (file_exists($image_baseurl . '/' . $images__value)) {
                        $content = str_replace($images__value, 'cid:' . $image_cid, $content);
                        $mail->addEmbeddedImage($image_baseurl . '/' . $images__value, $image_cid);
                    }
                }
            }

            $mail->Subject = $subject;
            $mail->Body = $content;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\r\n", $content));
            if ($attachments !== null) {
                if (!is_array($attachments) || isset($attachments['file'])) {
                    $attachments = [$attachments];
                }
                if (!empty($attachments)) {
                    foreach ($attachments as $attachments__value) {
                        if (is_string($attachments__value) && $attachments__value != '') {
                            $attachments__value = self::checkAndFormatAttachment($attachments__value);
                            $mail->addAttachment($attachments__value);
                        } elseif (is_array($attachments__value)) {
                            if (
                                isset($attachments__value['file']) &&
                                $attachments__value['file'] != '' &&
                                isset($attachments__value['name']) &&
                                $attachments__value['name'] != ''
                            ) {
                                $attachments__value['file'] = self::checkAndFormatAttachment(
                                    $attachments__value['file']
                                );
                                $mail->addAttachment($attachments__value['file'], $attachments__value['name']);
                            } elseif (
                                isset($attachments__value['file']) &&
                                $attachments__value['file'] != '' &&
                                file_exists($attachments__value['file'])
                            ) {
                                $attachments__value['file'] = self::checkAndFormatAttachment(
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

    public static function viewMail($mailbox = null, $folder = null, $id = null)
    {
        self::parseConfig();
        self::validateInput('viewMail', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders = $client->getFolders(false);

        $return = null;
        foreach ($folders as $folders__value) {
            if (
                $folder !== null &&
                $folders__value->full_name !== $folder &&
                self::decodeImapUtf7($folders__value->full_name) !== $folder
            ) {
                continue;
            }
            $message = $folders__value->query()->whereMessageId($id)->get()->first();
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }

            $mail = self::getMailDataBasic($message);

            $mail->eml =
                'data:message/rfc822;base64,' .
                base64_encode(json_decode(json_encode($message->getHeader()), true)['raw'] . $message->getRawBody());
            $mail->content_html = $message->getHTMLBody();
            $mail->content_plain = $message->getTextBody();

            $mail->attachments = [];
            $attachments = $message->getAttachments();
            if (!empty($attachments)) {
                foreach ($attachments as $attachments__value) {
                    $mail->attachments[] = (object) [
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
                $mail->content_html = str_replace(
                    'cid:' . $attachments__value->getId(),
                    'data:' .
                        $attachments__value->getMimeType() .
                        ';base64,' .
                        base64_encode($attachments__value->getContent()),
                    $mail->content_html
                );
            }

            $return = $mail;
            break;
        }

        $client->disconnect();
        return $return;
    }

    public static function moveMail($mailbox = null, $folder = null, $id = null, $name = null)
    {
        self::parseConfig();
        self::validateInput('moveMail', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders = $client->getFolders(false);
        foreach ($folders as $folders__value) {
            if (
                $folder !== null &&
                $folders__value->full_name !== $folder &&
                self::decodeImapUtf7($folders__value->full_name) !== $folder
            ) {
                continue;
            }
            $message = $folders__value->query()->whereMessageId($id)->get()->first();
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }
            //print_r([$name, self::encodeImapUtf7($name)]);
            $message->move(self::encodeImapUtf7($name), false, true);
        }
        $client->disconnect();
        return true;
    }

    public static function deleteMail(
        $mailbox = null,
        $folder = null,
        $id = null,
        $move = null,
        $delete = null,
        $read = null,
        $unread = null
    ) {
        self::parseConfig();
        self::validateInput('deleteMail', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders = $client->getFolders(false);
        foreach ($folders as $folders__value) {
            if (
                $folder !== null &&
                $folders__value->full_name !== $folder &&
                self::decodeImapUtf7($folders__value->full_name) !== $folder
            ) {
                continue;
            }
            $message = $folders__value->query()->whereMessageId($id)->get()->first();
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }
            $message->delete();
        }
        $client->disconnect();
        return true;
    }

    public static function readMail($mailbox = null, $folder = null, $id = null)
    {
        self::parseConfig();
        self::validateInput('readMail', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders = $client->getFolders(false);
        foreach ($folders as $folders__value) {
            if (
                $folder !== null &&
                $folders__value->full_name !== $folder &&
                self::decodeImapUtf7($folders__value->full_name) !== $folder
            ) {
                continue;
            }
            $message = $folders__value->query()->whereMessageId($id)->get()->first();
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }
            $message->setFlag('Seen');
        }
        $client->disconnect();
        return true;
    }

    public static function unreadMail($mailbox = null, $folder = null, $id = null)
    {
        self::parseConfig();
        self::validateInput('unreadMail', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders = $client->getFolders(false);
        foreach ($folders as $folders__value) {
            if (
                $folder !== null &&
                $folders__value->full_name !== $folder &&
                self::decodeImapUtf7($folders__value->full_name) !== $folder
            ) {
                continue;
            }
            $message = $folders__value->query()->whereMessageId($id)->get()->first();
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }
            $message->unsetFlag('Seen');
        }
        $client->disconnect();
        return true;
    }

    /**
     * Get all folders from a mailbox.
     *
     * @param string $mailbox The email address of the mailbox (must be configured in config.json)
     * @return array Array of folder names (e.g. ['INBOX', 'INBOX/subfolder', 'Sent', 'Trash'])
     */
    #[McpTool(name: 'get_folders', description: 'List all available folders in a mailbox')]
    public static function getFolders($mailbox = null)
    {
        self::parseConfig();
        self::validateInput('getFolders', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $folders_raw = $client->getFolders(false);
        $folders = [];
        foreach ($folders_raw as $folders_raw__value) {
            $folders[] = $folders_raw__value->full_name;
        }

        // sort folders alphabetically, but sort "INBOX" first
        usort($folders, function ($a, $b) {
            if (mb_strpos($a, 'INBOX') === 0 && mb_strpos($b, 'INBOX') !== 0) {
                return -1;
            }
            if (mb_strpos($a, 'INBOX') !== 0 && mb_strpos($b, 'INBOX') === 0) {
                return 1;
            }
            return strnatcasecmp($a, $b);
        });

        $client->disconnect();
        return $folders;
    }

    public static function createFolder($mailbox = null, $name = null)
    {
        self::parseConfig();
        self::validateInput('createFolder', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();

        $success = true;
        $client->createFolder($name, false);

        $client->disconnect();
        return $success;
    }

    public static function renameFolder($mailbox = null, $name_old = null, $name_new = null)
    {
        self::parseConfig();
        self::validateInput('renameFolder', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();

        $success = false;
        $folders = $client->getFolders(false);
        foreach ($folders as $folders__value) {
            if (
                $folders__value->full_name !== $name_old &&
                self::decodeImapUtf7($folders__value->full_name) !== $name_old
            ) {
                continue;
            }
            //$folders__value->move( self::encodeImapUtf7($name_new), false);
            $client
                ->getConnection()
                ->renameFolder(self::encodeImapUtf7($folders__value->full_name), self::encodeImapUtf7($name_new))
                ->validatedData();
            $success = true;
            break;
        }

        $client->disconnect();
        return $success;
    }

    public static function deleteFolder($mailbox = null, $name = null)
    {
        self::parseConfig();
        self::validateInput('deleteFolder', get_defined_vars());
        $settings = self::setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();

        $success = false;
        $folders = $client->getFolders(false);
        foreach ($folders as $folders__value) {
            if ($folders__value->full_name !== $name && self::decodeImapUtf7($folders__value->full_name) !== $name) {
                continue;
            }
            $folders__value->delete(false);
            $success = true;
            break;
        }

        $client->disconnect();
        return $success;
    }

    public static function getConfig()
    {
        self::parseConfig();
        return self::$config;
    }

    private static function parseConfig()
    {
        $configPath = self::getBasePath() . '/config.json';
        if (!file_exists($configPath)) {
            throw new \Exception('Configuration file not found: ' . $configPath);
        }
        $configContent = file_get_contents($configPath);
        self::$config = json_decode($configContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error decoding configuration file: ' . json_last_error_msg());
        }
    }

    private static function getBasePath()
    {
        $path = __DIR__;

        // go up until we find config.json
        while (!file_exists($path . '/config.json')) {
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }

        return $path;
    }

    public static function parseCli()
    {
        $args = $_SERVER['argv'];

        // parse action
        $action = $args[1] ?? null;
        $actions = [
            'fetch-mail',
            'send-mail',
            'view-mail',
            'move-mail',
            'delete-mail',
            'read-mail',
            'unread-mail',
            'get-folders',
            'create-folder',
            'rename-folder',
            'delete-folder',
            'get-config'
        ];
        if (
            !in_array(
                $action,

                $actions
            )
        ) {
            echo 'Usage: mailhelper [' . implode('|', $actions) . "] [options]\n";
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
        if (isset($options['filter-content'])) {
            $filter['content'] = $options['filter-content'];
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
            if ($action === 'fetch-mail') {
                $response = mailhelper::fetch(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    filter: !empty($filter) ? $filter : null,
                    limit: isset($options['limit']) ? (int) $options['limit'] : 100,
                    order: $options['order'] ?? null
                );
            }

            if ($action === 'send-mail') {
                $response = mailhelper::send(
                    mailbox: $options['mailbox'] ?? null,
                    subject: $options['subject'] ?? null,
                    content: $options['content'] ?? null,
                    from_name: $options['from_name'] ?? null,
                    to: $to,
                    cc: $cc,
                    bcc: $bcc,
                    attachments: $attachments
                );
            }

            if ($action === 'view-mail') {
                $response = mailhelper::view(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    id: $options['id'] ?? null
                );
            }

            if ($action === 'edit-mail') {
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

            if ($action === 'get-folders') {
                $response = mailhelper::getFolders(mailbox: $options['mailbox'] ?? null);
            }

            if ($action === 'create-folder') {
                $response = mailhelper::createFolder(
                    mailbox: $options['mailbox'] ?? null,
                    name: $options['name'] ?? null
                );
            }

            if ($action === 'rename-folder') {
                $response = mailhelper::renameFolders(
                    mailbox: $options['mailbox'] ?? null,
                    name_old: $options['name_old'] ?? null,
                    name_new: $options['name_new'] ?? null
                );
            }

            if ($action === 'delete-folders') {
                $response = mailhelper::deleteFolder(
                    mailbox: $options['mailbox'] ?? null,
                    name: $options['name'] ?? null
                );
            }

            if ($action === 'get-config') {
                $response = mailhelper::config();
            }

            if ($response !== null) {
                $response = self::prettyPrint($response);
                echo $response;
                die();
            }
        } catch (\Exception $e) {
            echo '⛔ ';
            echo $e->getMessage() . "\n";
            die();
        }
    }

    public static function isCli()
    {
        return php_sapi_name() === 'cli' && isset($_SERVER['argv']) && basename($_SERVER['argv'][0]) !== 'phpunit';
    }

    private static function prettyPrint($data, $indent = 0)
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
                        $output .= self::prettyPrint($value, $indent + 1);
                    } else {
                        if ($is_assoc) {
                            $output .=
                                $prefix . "\033[1;36m" . $key . ":\033[0m " . self::prettyPrintValue($value) . "\n";
                        } else {
                            $output .= $prefix . '- ' . self::prettyPrintValue($value) . "\n";
                        }
                    }
                }
            }
        } else {
            $output .= $prefix . self::prettyPrintValue($data) . "\n";
        }

        return $output;
    }

    private static function prettyPrintValue($value)
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

    private static function checkFilter($filter, $message)
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
        if ($perc < 0) {
            $perc = 0;
        }
        if ($perc > 100) {
            $perc = 100;
        }
        $bar = round(($width * $perc) / 100);
        echo sprintf(
            "%s[%s%s] %s\r",
            $info != '' ? $info . ' ' : '',
            str_repeat($char, $bar) . ($perc < 100 ? '>' : ''),
            $perc == 100 ? $char : str_repeat(' ', $width - $bar),
            str_pad($perc, 3, ' ', STR_PAD_LEFT) . '%'
        );
    }

    private static function validateInput($action, $args)
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
        if ($action === 'fetchMails') {
        }
        if ($action === 'sendMail') {
            if (!($args['subject'] ?? null)) {
                throw new \Exception('Missing subject.');
            }
            if (!($args['content'] ?? null)) {
                throw new \Exception('Missing content.');
            }
            if (!($args['to'] ?? null)) {
                throw new \Exception('Missing to.');
            }
        }
        if ($action === 'viewMail') {
            if (!($args['id'] ?? null)) {
                throw new \Exception('Missing id.');
            }
        }
        if ($action === 'moveMail') {
            if (!($args['id'] ?? null)) {
                throw new \Exception('Missing id.');
            }
        }
        if ($action === 'deleteMail') {
            if (!($args['id'] ?? null)) {
                throw new \Exception('Missing id.');
            }
        }
        if ($action === 'readMail') {
            if (!($args['id'] ?? null)) {
                throw new \Exception('Missing id.');
            }
        }
        if ($action === 'unreadMail') {
            if (!($args['id'] ?? null)) {
                throw new \Exception('Missing id.');
            }
        }
        if ($action === 'getFolders') {
        }
        if ($action === 'createFolder') {
            if (!($args['name'] ?? null)) {
                throw new \Exception('Missing name.');
            }
        }
        if ($action === 'renameFolder') {
            if (!($args['name_old'] ?? null) || !($args['name_new'] ?? null)) {
                throw new \Exception('Missing name.');
            }
        }
        if ($action === 'deleteFolder') {
            if (!($args['name'] ?? null)) {
                throw new \Exception('Missing name.');
            }
        }
    }

    private static function setupSettings($mailbox)
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

    private static function getMailDataBasic($message)
    {
        $mail = (object) [];
        $mail->id = $message->getMessageId()->toString();

        foreach (['from' => 'getFrom', 'to' => 'getTo', 'cc' => 'getCc'] as $fields__key => $fields__value) {
            $mail->$fields__key = [];
            $addresses = $message->$fields__value()->toArray();
            foreach ($addresses as $addresses__value) {
                $mail->$fields__key[] = (object) [
                    'name' => $addresses__value->personal ?? '',
                    'email' => $addresses__value->mail ?? ''
                ];
            }
        }

        $mail->date = $message->getDate()->toDate()->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s');

        $subject = $message->getSubject()[0] ?? '';
        $subject = trim($subject);
        $subject = preg_replace("/\r\n|\r|\n/", '', trim(@$message->getSubject()[0]));
        if (mb_detect_encoding($subject, 'UTF-8, ISO-8859-1') !== 'UTF-8') {
            $subject = self::utf8EncodeLegacy($subject);
        }
        $mail->subject = $subject;

        $flags = $message->getFlags()->toArray();
        $mail->seen = !empty($flags) && in_array('Seen', $flags);

        return $mail;
    }

    private static function checkAndFormatAttachment($attachment)
    {
        if (!file_exists($attachment)) {
            $attachment = self::getBasePath() . '/' . ltrim($attachment, '/');
        }
        if (!file_exists($attachment)) {
            throw new \Exception('Attachment file not found: ' . $attachment);
        }
        return $attachment;
    }

    private static function utf8EncodeLegacy($str)
    {
        return \UConverter::transcode($str, 'UTF8', 'ISO-8859-1');
    }

    public static function encodeImapUtf7($string)
    {
        // Wenn nur ASCII, keine Kodierung nötig
        if (!preg_match('/[^\x20-\x7E]/', $string)) {
            return $string;
        }

        // Manuelles mUTF-7 Encoding (RFC 3501)
        // NICHT mb_convert_encoding verwenden - es funktioniert nicht korrekt!
        $result = '';
        $length = mb_strlen($string, 'UTF-8');
        $base64Buffer = '';

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($string, $i, 1, 'UTF-8');
            $ord = mb_ord($char, 'UTF-8');

            if ($ord >= 0x20 && $ord <= 0x7e) {
                // ASCII printable - flush buffer first
                if ($base64Buffer !== '') {
                    $utf16 = mb_convert_encoding($base64Buffer, 'UTF-16BE', 'UTF-8');
                    $encoded = base64_encode($utf16);
                    $encoded = rtrim($encoded, '=');
                    $encoded = str_replace('/', ',', $encoded);
                    $result .= '&' . $encoded . '-';
                    $base64Buffer = '';
                }
                if ($char === '&') {
                    $result .= '&-';
                } else {
                    $result .= $char;
                }
            } else {
                // Non-ASCII - sammeln
                $base64Buffer .= $char;
            }
        }

        // Restlichen Buffer
        if ($base64Buffer !== '') {
            $utf16 = mb_convert_encoding($base64Buffer, 'UTF-16BE', 'UTF-8');
            $encoded = base64_encode($utf16);
            $encoded = rtrim($encoded, '=');
            $encoded = str_replace('/', ',', $encoded);
            $result .= '&' . $encoded . '-';
        }

        return $result;
    }

    public static function decodeImapUtf7($string)
    {
        // Schneller Check: Wenn kein & vorhanden, ist es kein mUTF-7
        if (strpos($string, '&') === false) {
            return $string;
        }

        // Versuche mb_convert_encoding mit UTF7-IMAP
        if (function_exists('mb_convert_encoding')) {
            $encodings = mb_list_encodings();
            if (in_array('UTF7-IMAP', $encodings)) {
                $decoded = @mb_convert_encoding($string, 'UTF-8', 'UTF7-IMAP');
                if ($decoded !== false && $decoded !== '') {
                    return $decoded;
                }
            }
        }

        // Fallback: Manuelles Decoding
        $result = '';
        $length = strlen($string);
        $i = 0;

        while ($i < $length) {
            if ($string[$i] === '&') {
                if ($i + 1 < $length && $string[$i + 1] === '-') {
                    // &- ist escaped &
                    $result .= '&';
                    $i += 2;
                } else {
                    // Base64 encoded section finden
                    $end = strpos($string, '-', $i + 1);
                    if ($end === false) {
                        $end = $length;
                    }
                    $encoded = substr($string, $i + 1, $end - $i - 1);
                    $encoded = str_replace(',', '/', $encoded); // mUTF-7 , zurück zu /

                    // Padding hinzufügen
                    $padding = strlen($encoded) % 4;
                    if ($padding > 0) {
                        $encoded .= str_repeat('=', 4 - $padding);
                    }

                    $decoded = base64_decode($encoded);
                    if ($decoded !== false) {
                        $result .= mb_convert_encoding($decoded, 'UTF-8', 'UTF-16BE');
                    }
                    $i = $end + 1;
                }
            } else {
                $result .= $string[$i];
                $i++;
            }
        }

        return $result;
    }
}

if (mailhelper::isCli()) {
    mailhelper::parseCli();
}
