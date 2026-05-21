<?php
namespace vielhuber\mailhelper;

error_reporting(E_ALL & ~E_DEPRECATED);

// autoloader: try different paths depending on installation method
foreach (
    [
        __DIR__ . '/../vendor/autoload.php', // local development
        __DIR__ . '/../../../autoload.php', // installed via composer
        __DIR__ . '/../../../../autoload.php' // alternative composer path
    ]
    as $autoloadPath
) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use Webklex\PHPIMAP\ClientManager;
use PHPMailer\PHPMailer\PHPMailer;

class mailhelper
{
    public array $config = [];

    public function __construct($config = null)
    {
        if ($config !== null && !empty($config)) {
            $this->config = $config;
        } else {
            $this->config = $this->loadConfig();
        }
    }

    /**
     * Fetch emails from a configured mailbox via IMAP.
     *
     * Returns an envelope with `count` (number of returned mails) and `items`
     * (array of mail objects). Use `count` as the authoritative number.
     *
     * @param string $mailbox Email address of the mailbox (must exist in config.json).
     * @param string $folder Folder name to fetch from (e.g. 'INBOX').
     * @param array|null $filter Structured filter — properties: date_from, date_until, subject, to. Omit for no filter.
     * @param int|null $limit Maximum number of emails to return. Default 50. Pick a small value (e.g. 10) when the user asks for few mails.
     * @param string|null $order Sort order: 'desc' (newest first, default) or 'asc' (oldest first).
     */
    #[
        McpTool(
            name: 'fetch_mails',
            description: 'Fetch email headers from a mailbox folder. Supports structured filtering, limit (default 50) and sort order. Returns {count, items} envelope with date-sorted results. Keep progress false for MCP calls.'
        )
    ]
    public function fetchMails(
        string $mailbox,
        string $folder,
        #[
            Schema(
                type: 'object',
                properties: [
                    'date_from' => [
                        'type' => 'string',
                        'format' => 'date',
                        'description' => 'Inclusive lower date bound (YYYY-MM-DD).'
                    ],
                    'date_until' => [
                        'type' => 'string',
                        'format' => 'date',
                        'description' => 'Inclusive upper date bound (YYYY-MM-DD).'
                    ],
                    'subject' => ['type' => 'string', 'description' => 'Substring that must appear in the subject.'],
                    'content' => ['type' => 'string', 'description' => 'Substring that must appear in the body.'],
                    'from' => ['type' => 'string', 'description' => 'Sender email address to match.'],
                    'to' => ['type' => 'string', 'description' => 'Recipient email address to match.'],
                    'cc' => ['type' => 'string', 'description' => 'CC email address to match.']
                ],
                additionalProperties: false
            )
        ]
        ?array $filter = null,
        #[Schema(type: 'integer', minimum: 1)] ?int $limit = 50,
        #[Schema(type: 'string', enum: ['asc', 'desc'])] ?string $order = null,
        #[Schema(type: 'boolean', description: 'Show CLI progress output. Keep false for MCP calls.')]
        bool $progress = false
    ): array {
        $filter = self::parseJsonParam($filter);
        $this->validateInput('fetchMails', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $mails = [];

        $order_str =
            $order !== null && in_array(mb_strtolower($order), ['asc', 'desc']) ? mb_strtolower($order) : 'desc';
        $fix_order = $this->config[$mailbox]['imap']['fix_order'] ?? false;
        $show_progress = self::isCli() && $progress === true;

        $cm = new ClientManager([
            'date_format' => 'd-M-Y',
            'options' => [
                'unescaped_search_dates' => true
            ]
        ]);
        $client = $cm->make($settings);
        $client->connect();
        try {
            $sourceFolder = self::findFolderOrFail($client, $folder);

            $query = $sourceFolder->query();
            $searchQuery = [['ALL']];
            if ($filter !== null && !empty($filter)) {
                $searchQuery = [];
                $hasUtf8 = false;
                if ($filter['date_from'] ?? null) {
                    $searchQuery[] = ['SINCE', (new \DateTime((string) $filter['date_from']))->format('d-M-Y')];
                }
                if ($filter['date_until'] ?? null) {
                    $searchQuery[] = [
                        'BEFORE',
                        (new \DateTime((string) $filter['date_until']))->modify('+1 day')->format('d-M-Y')
                    ];
                }
                foreach (
                    [
                        'subject' => 'SUBJECT',
                        'content' => 'BODY',
                        'from' => 'FROM',
                        'to' => 'TO',
                        'cc' => 'CC'
                    ]
                    as $filterKey => $criteria
                ) {
                    if (!($filter[$filterKey] ?? null)) {
                        continue;
                    }
                    $value = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $filter[$filterKey]);
                    if (preg_match('/[^\x00-\x7F]/u', $value) === 1) {
                        $hasUtf8 = true;
                    }
                    $searchQuery[] = [$criteria, $value];
                }
                if (empty($searchQuery)) {
                    $searchQuery = [['ALL']];
                }
                if ($hasUtf8) {
                    array_unshift($searchQuery, ['CHARSET UTF-8']);
                }
            }
            $query->setQuery($searchQuery);
            $query->leaveUnread();
            $query->setFetchBody(false);

            // some imap servers ignore fetch order, so we fetch everything and sort afterwards
            if ($fix_order) {
                $page = 1;
                $full_count = null;
                $paginator_limit = 10;
                while (true) {
                    if ($full_count !== null && ($page - 1) * $paginator_limit >= $full_count) {
                        break;
                    }
                    try {
                        $paginator = $query->paginate($paginator_limit, $page, 'imap_page');
                    } catch (\Throwable $e) {
                        break;
                    }
                    if ($paginator->count() === 0) {
                        break;
                    }
                    $full_count = $paginator->total();
                    $messages = iterator_to_array($paginator);
                    foreach ($messages as $messages__value) {
                        $mail = self::getMailDataBasic($messages__value);
                        $mails[] = $mail;
                        if ($show_progress) {
                            self::progress(count($mails), $full_count, 'Fetching emails...');
                        }
                    }
                    $page++;
                }
            } else {
                $query->setFetchOrder($order_str);
                if ($limit !== null) {
                    $query->limit($limit);
                }
                $messages = $query->get();
                $full_count = $messages->total() ?? $messages->count();

                foreach ($messages as $messages__value) {
                    $mail = self::getMailDataBasic($messages__value);
                    $mails[] = $mail;
                    if ($show_progress) {
                        self::progress(count($mails), $full_count, 'Fetching emails...');
                    }
                }
            }

            if ($fix_order) {
                // apply sort afterwards
                usort($mails, function ($a, $b) use ($order_str) {
                    if ($order_str === 'asc') {
                        return strtotime($a->date) <=> strtotime($b->date);
                    }
                    return strtotime($b->date) <=> strtotime($a->date);
                });

                // apply limit afterwards
                if ($limit !== null && count($mails) > $limit) {
                    $mails = array_slice($mails, 0, $limit);
                }
            }

            if ($show_progress) {
                echo PHP_EOL;
            }

            return ['count' => count($mails), 'items' => $mails];
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Send an email via SMTP.
     *
     * Sends an HTML email with optional attachments through the configured SMTP server.
     * Supports multiple recipients (To, CC, BCC), embedded images,
     * and file attachments. Automatically generates a plain-text alternative body if none is provided.
     *
     * @param string $mailbox Sender email address (required, must exist in config.json with SMTP settings)
     * @param string $subject Email subject line (required)
     * @param string $content Email body (required, HTML supported)
     * @param string $to Recipient(s): bare email 'john@example.com', RFC 5322 'John <john@example.com>', comma-separated list, or JSON array [{"name": "John", "email": "john@example.com"}] (required)
     * @param string|null $cc CC recipient(s), identical format options as $to: bare email, RFC 5322 'Name <email>', comma-separated list, or JSON array (optional)
     * @param string|null $bcc BCC recipient(s), identical format options as $to: bare email, RFC 5322 'Name <email>', comma-separated list, or JSON array (optional)
     * @param string|null $from_name Sender display name (optional, overrides config if set)
     * @param string|null $attachments File path or array [{"name": "doc.pdf", "file": "/path/to/file.pdf"}] (optional)
     * @param string|null $content_plain Plain-text alternative body (optional, generated from HTML content if omitted)
     * @return bool True if email was sent successfully
     * @throws \Exception If SMTP connection fails or sending fails
     */
    #[
        McpTool(
            name: 'send_mail',
            description: 'Send an HTML email via SMTP. Recipients accept: bare email "a@b.com", RFC 5322 "Name <a@b.com>", comma-separated list, or JSON array [{"name": "John", "email": "john@example.com"}]. Attachments as path or JSON: [{"name": "doc.pdf", "file": "/path/to/file.pdf"}]. HTML images are embedded automatically. content_plain can override the generated plain-text alternative body.'
        )
    ]
    public function sendMail(
        string $mailbox,
        string $subject,
        string $content,
        #[Schema(type: 'string')] string|array $to,
        #[Schema(type: 'string')] string|array|null $cc = null,
        #[Schema(type: 'string')] string|array|null $bcc = null,
        #[Schema(type: 'string')] ?string $from_name = null,
        #[Schema(type: 'string')] string|array|null $attachments = null,
        #[Schema(type: 'string')] ?string $content_plain = null
    ): bool {
        $to = self::parseJsonParam($to);
        $cc = self::parseJsonParam($cc);
        $bcc = self::parseJsonParam($bcc);
        $attachments = self::parseJsonParam($attachments);
        $this->validateInput('sendMail', get_defined_vars());

        $mail = $this->buildMessage($mailbox, $subject, $content, $to, $cc, $bcc, $from_name, $attachments, $content_plain);
        try {
            $mail->send();
            return true;
        } catch (\Exception $e) {
            throw new \Exception(
                'Message could not be sent. (' . $e->getMessage() . ') - Mailer Error: ' . ($mail->ErrorInfo ?? '-')
            );
        }
    }

    private function buildMessage(
        string $mailbox,
        string $subject,
        string $content,
        string|array $to,
        string|array|null $cc = null,
        string|array|null $bcc = null,
        ?string $from_name = null,
        string|array|null $attachments = null,
        ?string $content_plain = null
    ): \PHPMailer\PHPMailer\PHPMailer {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config[$mailbox]['smtp']['host'] ?? null;
        $mail->Port = $this->config[$mailbox]['smtp']['port'] ?? null;
        $mail->SMTPSecure = $this->config[$mailbox]['smtp']['encryption'] ?? null;
        $mail->setFrom($mailbox, $from_name ?? '');
        $mail->SMTPAuth = true;
        $mail->CharSet = 'utf-8';
        $mail->isHTML(true);
        $mail->SMTPOptions = [
            'tls' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
        ];

        if ($this->config[$mailbox]['smtp']['tenant_id'] ?? null) {
            $mail->AuthType = 'XOAUTH2';
            $mail->setOAuth(
                new class (
                    $mailbox,
                    $this->getMicrosoftOAuthToken(
                        $this->config[$mailbox]['smtp']['tenant_id'],
                        $this->config[$mailbox]['smtp']['client_id'],
                        $this->config[$mailbox]['smtp']['client_secret']
                    )
                ) implements \PHPMailer\PHPMailer\OAuthTokenProvider {
                    private string $userEmail;
                    private string $accessToken;
                    public function __construct(string $userEmail, string $accessToken)
                    {
                        $this->userEmail = $userEmail;
                        $this->accessToken = $accessToken;
                    }
                    public function getOauth64(): string
                    {
                        return base64_encode(
                            'user=' . $this->userEmail . "\001auth=Bearer " . $this->accessToken . "\001\001"
                        );
                    }
                }
            );
        } else {
            $mail->Username = $this->config[$mailbox]['smtp']['username'] ?? null;
            $mail->Password = $this->config[$mailbox]['smtp']['password'] ?? null;
        }

        foreach (['to' => 'addAddress', 'cc' => 'addCC', 'bcc' => 'addBCC'] as $fields__key => $fields__value) {
            if (!is_array(${$fields__key}) || isset(${$fields__key}['email'])) {
                ${$fields__key} = [${$fields__key}];
            }
            foreach (${$fields__key} as $recipients__value) {
                if (is_string($recipients__value) && $recipients__value != '') {
                    // tolerate RFC 5322 "Name <email>" format and comma-separated lists
                    foreach (preg_split('/\s*,\s*/', $recipients__value, -1, PREG_SPLIT_NO_EMPTY) as $part) {
                        if (preg_match('/^\s*(.*?)\s*<\s*([^>]+)\s*>\s*$/', $part, $m)) {
                            $name = trim($m[1], " \t\"'");
                            $email = trim($m[2]);
                            if ($email !== '' && $name !== '') {
                                $mail->$fields__value($email, $name);
                            } elseif ($email !== '') {
                                $mail->$fields__value($email);
                            }
                        } else {
                            $mail->$fields__value(trim($part));
                        }
                    }
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

        $images = [];
        preg_match_all('/src="([^"]*)"/i', $content, $images);
        $images = array_unique($images[1]);
        foreach ($images as $images__value) {
            if (strpos($images__value, 'cid:') !== false || strpos($images__value, 'http') === 0) {
                continue;
            }
            $is_absolute_local_image =
                (strpos($images__value, '/') === 0 || preg_match('/^[A-Za-z]:[\\\\\\/]/', $images__value) === 1) &&
                file_exists($images__value);
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

            $image_baseurl = $_SERVER['DOCUMENT_ROOT'] ?? '';

            $image_tmp_path = sys_get_temp_dir() . '/' . md5(uniqid()) . '.' . $image_extension;

            if (strpos($images__value, 'base64,') !== false) {
                file_put_contents(
                    $image_tmp_path,
                    base64_decode(
                        trim(substr($images__value, strpos($images__value, 'base64,') + strlen('base64')))
                    )
                );
                $content = str_replace($images__value, 'cid:' . $image_cid, $content);
                $mail->addEmbeddedImage($image_tmp_path, $image_cid);
            } elseif ($is_absolute_local_image === true) {
                $content = str_replace($images__value, 'cid:' . $image_cid, $content);
                $mail->addEmbeddedImage($images__value, $image_cid);
            } elseif (file_exists($image_baseurl . '/' . $images__value)) {
                $content = str_replace($images__value, 'cid:' . $image_cid, $content);
                $mail->addEmbeddedImage($image_baseurl . '/' . $images__value, $image_cid);
            }
        }

        $mail->Subject = $subject;
        $mail->Body = $content;
        $mail->AltBody = $content_plain ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\r\n", $content));
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
        return $mail;
    }

    /**
     * Save an HTML email as a draft in the auto-detected IMAP Drafts folder.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $subject Email subject (required)
     * @param string $content HTML email content (required)
     * @param string|array $to Recipients (required)
     * @param string|array|null $cc CC recipients (optional)
     * @param string|array|null $bcc BCC recipients (optional)
     * @param string|null $from_name Display name for the From header (optional)
     * @param string|array|null $attachments Attachment paths or [{name,file}] objects (optional)
     * @param string|null $content_plain Plain-text alternative body (optional)
     * @return bool True when the draft was successfully appended.
     * @throws \Exception On IMAP failure, build failure, or when the Drafts folder cannot be located.
     */
    #[
        McpTool(
            name: 'save_draft',
            description: 'Save an HTML email as a draft in the IMAP Drafts folder. Same parameters as send_mail. The Drafts folder is auto-detected by name (Drafts, Entwürfe, INBOX.Drafts, [Gmail]/Drafts, …).'
        )
    ]
    public function saveDraft(
        string $mailbox,
        string $subject,
        string $content,
        #[Schema(type: 'string')] string|array $to,
        #[Schema(type: 'string')] string|array|null $cc = null,
        #[Schema(type: 'string')] string|array|null $bcc = null,
        #[Schema(type: 'string')] ?string $from_name = null,
        #[Schema(type: 'string')] string|array|null $attachments = null,
        #[Schema(type: 'string')] ?string $content_plain = null
    ): bool {
        $to = self::parseJsonParam($to);
        $cc = self::parseJsonParam($cc);
        $bcc = self::parseJsonParam($bcc);
        $attachments = self::parseJsonParam($attachments);
        $this->validateInput('saveDraft', get_defined_vars());

        $mail = $this->buildMessage($mailbox, $subject, $content, $to, $cc, $bcc, $from_name, $attachments, $content_plain);
        if (!$mail->preSend()) {
            throw new \Exception('Could not build draft. Mailer Error: ' . ($mail->ErrorInfo ?? '-'));
        }
        $rfc822 = $mail->getSentMIMEMessage();

        $settings = $this->setupSettings($mailbox);
        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $drafts = self::findDraftsFolder($client);
            $drafts->appendMessage($rfc822, ['\\Draft'], \Carbon\Carbon::now());
            return true;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Send an existing draft via SMTP, then expunge it from the auto-detected Drafts folder.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $id Message-ID of the draft to send (required)
     * @return bool True when the draft was sent and removed.
     * @throws \Exception On IMAP failure, missing draft, or SMTP failure.
     */
    #[
        McpTool(
            name: 'send_draft',
            description: 'Send an existing draft via SMTP and remove it from the auto-detected Drafts folder. Specify mailbox + draft message-id.'
        )
    ]
    public function sendDraft(string $mailbox, string $id): bool
    {
        $this->validateInput('sendDraft', get_defined_vars());
        $settings = $this->setupSettings($mailbox);
        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        $temp_attachment_paths = [];
        try {
            $sourceFolder = self::findDraftsFolder($client);
            $message = self::findMessageByMessageId($sourceFolder, $id);
            if (!$message) {
                throw new \Exception('Draft id not found: ' . $id);
            }

            $subject = (string) ($message->getSubject() ?? '');
            $to = self::imapAddressesToArray($message->getTo());
            $cc = self::imapAddressesToArray($message->getCc());
            $bcc = self::imapAddressesToArray($message->getBcc());
            $content = (string) ($message->getHTMLBody() ?: $message->getTextBody() ?: '');
            $content_plain = $message->getTextBody();
            $content_plain = $content_plain !== '' ? (string) $content_plain : null;

            $from_name = null;
            $from_list = self::imapAddressesToArray($message->getFrom());
            if (!empty($from_list) && ($from_list[0]['name'] ?? '') !== '') {
                $from_name = $from_list[0]['name'];
            }

            // skip inline cid: attachments — they're already in the HTML body
            $attachments = [];
            foreach ($message->getAttachments() as $attachment) {
                $disposition = strtolower((string) $attachment->disposition);
                if ($disposition === 'inline') {
                    continue;
                }
                $tmp_path = sys_get_temp_dir() . '/mailhelper_draft_' . uniqid() . '_' . basename((string) $attachment->name);
                if (@file_put_contents($tmp_path, $attachment->getContent()) === false) {
                    throw new \Exception('Failed to stage draft attachment: ' . $attachment->name);
                }
                $temp_attachment_paths[] = $tmp_path;
                $attachments[] = ['file' => $tmp_path, 'name' => (string) $attachment->name];
            }

            $mail = $this->buildMessage($mailbox, $subject, $content, $to, $cc, $bcc, $from_name, $attachments ?: null, $content_plain);
            try {
                $mail->send();
            } catch (\Exception $e) {
                throw new \Exception(
                    'Draft could not be sent. (' . $e->getMessage() . ') - Mailer Error: ' . ($mail->ErrorInfo ?? '-')
                );
            }

            $message->delete(true);
            return true;
        } finally {
            foreach ($temp_attachment_paths as $tmp) {
                @unlink($tmp);
            }
            $client->disconnect();
        }
    }

    // INBOX-prefixed variants come first — providers that use the INBOX
    // namespace also expose top-level aliases that would otherwise shadow them.
    private static function findDraftsFolder(\Webklex\PHPIMAP\Client $client): \Webklex\PHPIMAP\Folder
    {
        $candidates = [
            'INBOX.Drafts', 'INBOX/Drafts',
            'INBOX.Entwürfe', 'INBOX/Entwürfe',
            '[Gmail]/Drafts', '[Gmail]/Entwürfe',
            'Drafts', 'Entwürfe', 'Draft'
        ];
        $folders = $client->getFolders(false);
        $available = [];
        foreach ($folders as $f) {
            $encoded = $f->full_name;
            $decoded = self::decodeImapUtf7($encoded);
            $available[$encoded] = $f;
            $available[$decoded] = $f;
        }
        foreach ($candidates as $candidate) {
            if (isset($available[$candidate])) {
                return $available[$candidate];
            }
        }
        foreach ($available as $name => $folder) {
            $needle = mb_strtolower($name);
            if (mb_strpos($needle, 'draft') !== false || mb_strpos($needle, 'entwurf') !== false || mb_strpos($needle, 'entwürf') !== false) {
                return $folder;
            }
        }
        throw new \Exception(
            'Could not locate the Drafts folder. Available folders: ' .
                implode(', ', array_unique(array_keys($available)))
        );
    }

    private static function imapAddressesToArray($value): array
    {
        // webklex's getTo()/getCc()/getBcc()/getFrom() return Attribute
        // objects (ArrayAccess but not Iterator) — foreach over them iterates
        // the object's public properties, not the contained addresses. The
        // ->toArray() exposes the actual list of Address objects.
        if (is_object($value) && method_exists($value, 'toArray')) {
            $value = $value->toArray();
        }
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $addr) {
            if (!is_object($addr)) {
                continue;
            }
            $email = (string) ($addr->mail ?? '');
            if ($email === '' && isset($addr->mailbox, $addr->host) && $addr->mailbox !== '' && $addr->host !== '') {
                $email = $addr->mailbox . '@' . $addr->host;
            }
            if ($email === '') {
                continue;
            }
            $out[] = ['email' => $email, 'name' => (string) ($addr->personal ?? '')];
        }
        return $out;
    }

    /**
     * View a single email with full content, attachment paths and EML path.
     *
     * Retrieves the complete email. By default the raw EML and every attachment
     * (including inline CID images) are written to disk under a per-message
     * directory in /tmp and only their filesystem paths are returned — keeping
     * the response payload small enough that LLM consumers can see the
     * attachment metadata even when the body is long. CID references inside
     * `content_html` are rewritten to those filesystem paths so downstream
     * tools can fetch the image bytes directly when needed.
     *
     * The response field order is optimised for LLM consumption: structural
     * metadata (id, from, to, cc, date, subject) and the attachment list come
     * BEFORE the (potentially huge) HTML/plain bodies, so an LLM that gets a
     * truncated view still sees what's available.
     *
     * Pass `inline_files=true` to additionally include the raw EML and each
     * attachment's bytes inline as base64 data URIs (the legacy behaviour) —
     * useful for callers that need a self-contained payload.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $folder Folder containing the email (required, e.g., 'INBOX')
     * @param string $id Message-ID of the email to retrieve (required)
     * @param bool $inline_files Also include EML and attachment bytes inline as base64 data URIs (optional, default: false)
     * @return object Email object with: id, from, to, cc, date, subject, attachments (->name, ->mime_type, ->size, ->path, ->content when inline_files), eml (path string; or data-URI when inline_files), content_html, content_plain, seen
     * @throws \Exception If folder doesn't exist, message ID not found, or connection fails
     */
    #[
        McpTool(
            name: 'view_mail',
            description: 'Retrieve full email content. By default writes the EML and every attachment to disk under /tmp/mailhelper-output and returns their paths instead of inlining base64 — so attachments stay visible to the caller even when the body is long. Pass inline_files=true to additionally include EML/attachment bytes inline (base64 data URIs). CID image references inside content_html are rewritten to the on-disk paths.'
        )
    ]
    public function viewMail(string $mailbox, string $folder, string $id, bool $inline_files = false): object
    {
        $this->validateInput('viewMail', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $sourceFolder = self::findFolderOrFail($client, $folder);
            $message = self::findMessageByMessageId($sourceFolder, $id, true);
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }

            $mail = self::getMailDataBasic($message);

            $outBase = sys_get_temp_dir() . '/mailhelper-output';
            $slot = substr(md5($mailbox . '|' . ($mail->id !== '' ? $mail->id : $mail->uid)), 0, 16);
            $outDir = $outBase . '/' . $slot;
            if (!is_dir($outDir)) {
                @mkdir($outDir, 0775, true);
            }

            $rawEml = ($message->getHeader()->raw ?? '') . "\r\n\r\n" . $message->getRawBody();
            $emlPath = $outDir . '/_message.eml';
            @file_put_contents($emlPath, $rawEml);

            $attachments_meta = [];
            $cidToPath = [];
            $attachments_raw = $message->getAttachments();
            $idx = 0;
            foreach ($attachments_raw as $att) {
                $idx++;
                $rawName = (string) $att->getFilename();
                if ($rawName === '') {
                    $rawName = 'attachment_' . $idx;
                }
                $safeName = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $rawName);
                if ($safeName === '' || $safeName === '.' || $safeName === '..') {
                    $safeName = 'attachment_' . $idx;
                }
                $path = $outDir . '/' . sprintf('%02d_%s', $idx, $safeName);
                $bytes = $att->getContent();
                @file_put_contents($path, $bytes);

                $entry = (object) [
                    'name' => $rawName,
                    'mime_type' => $att->getContentType(),
                    'size' => strlen($bytes),
                    'path' => $path
                ];
                if ($inline_files) {
                    $entry->content = 'data:' . $att->getContentType() . ';base64,' . base64_encode($bytes);
                }
                $attachments_meta[] = $entry;

                $cid = (string) $att->getId();
                if ($cid !== '') {
                    $cidToPath[$cid] = $path;
                }
            }
            $mail->attachments = $attachments_meta;

            $mail->eml = $inline_files ? 'data:message/rfc822;base64,' . base64_encode($rawEml) : $emlPath;

            $body = $message->getHTMLBody();
            $body = trim($body);
            $body = preg_replace('/\r\n|\r|\n/', "\n", $body);
            $body = preg_replace('/\n\s+/', "\n", $body);
            $body = preg_replace('/\s+/', ' ', $body);
            $body = preg_replace('/\n\n+/', "\n", $body);
            foreach ($attachments_raw as $att) {
                $cid = (string) $att->getId();
                if ($cid === '') {
                    continue;
                }
                $replacement = $inline_files
                    ? 'data:' . $att->getMimeType() . ';base64,' . base64_encode($att->getContent())
                    : $cidToPath[$cid] ?? '';
                if ($replacement === '') {
                    continue;
                }
                $body = str_replace('cid:' . $cid, $replacement, $body);
            }
            $mail->content_html = $body;

            $body = $message->getTextBody();
            if (empty($body)) {
                $body = $message->getHTMLBody()
                    ? strip_tags(
                        str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $message->getHTMLBody())
                    )
                    : '';
            }
            $body = trim($body);
            $body = preg_replace('/\r\n|\r|\n/', "\n", $body);
            $body = preg_replace('/\n\s+/', "\n", $body);
            $body = preg_replace('/\s+/', ' ', $body);
            $body = preg_replace('/\n\n+/', "\n", $body);
            $mail->content_plain = $body;

            return $mail;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Move an email to a different folder.
     *
     * Moves a single email identified by its Message-ID to a target folder.
     * Folder names with special characters (umlauts) are automatically encoded to mUTF-7.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $folder Source folder containing the email (required, e.g., 'INBOX')
     * @param string $id Message-ID of the email to move (required)
     * @param string $name Target folder name (required, e.g., 'Archive', 'INBOX/Subfolder')
     * @return bool True if move was successful
     * @throws \Exception If message ID not found or folder doesn't exist
     */
    #[
        McpTool(
            name: 'move_mail',
            description: 'Move an email to a different folder within the mailbox. Supports folders with special characters.'
        )
    ]
    public function moveMail(string $mailbox, string $folder, string $id, string $name): bool
    {
        $this->validateInput('moveMail', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $folders = $client->getFolders(false);
            $allNames = [];
            $decodedOnly = [];
            $sourceFolder = null;
            foreach ($folders as $folders__value) {
                $encoded = $folders__value->full_name;
                $decoded = self::decodeImapUtf7($encoded);
                $allNames[] = $encoded;
                if ($decoded !== $encoded) {
                    $allNames[] = $decoded;
                }
                $decodedOnly[] = $decoded;
                if ($encoded === $folder || $decoded === $folder) {
                    $sourceFolder = $folders__value;
                }
            }
            if ($sourceFolder === null) {
                throw new \Exception(
                    'Source folder does not exist: "' . $folder . '". Available folders: ' . implode(', ', $decodedOnly)
                );
            }
            if (!in_array($name, $allNames, true)) {
                throw new \Exception(
                    'Target folder does not exist: "' . $name . '". Available folders: ' . implode(', ', $decodedOnly)
                );
            }

            $message = self::findMessageByMessageId($sourceFolder, $id);
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }
            $message->move(self::encodeImapUtf7($name), false, true);
            return true;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Delete an email permanently.
     *
     * Permanently removes an email from the mailbox. This action cannot be undone.
     * To move to trash instead, use moveMail() with the trash folder name.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $folder Folder containing the email (required, e.g., 'INBOX')
     * @param string $id Message-ID of the email to delete (required)
     * @return bool True if deletion was successful
     * @throws \Exception If message ID not found
     */
    #[
        McpTool(
            name: 'delete_mail',
            description: 'Permanently delete an email from the mailbox. Use move_mail to trash folder for soft delete.'
        )
    ]
    public function deleteMail(string $mailbox, string $folder, string $id): bool
    {
        $this->validateInput('deleteMail', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $sourceFolder = self::findFolderOrFail($client, $folder);
            $message = self::findMessageByMessageId($sourceFolder, $id);
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }
            $message->delete();
            return true;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Mark an email as read.
     *
     * Sets the 'Seen' IMAP flag on the specified email, marking it as read in the mailbox.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $folder Folder containing the email (required, e.g., 'INBOX')
     * @param string $id Message-ID of the email to mark as read (required)
     * @return bool True if flag was set successfully
     * @throws \Exception If message ID not found
     */
    #[McpTool(name: 'read_mail', description: 'Mark an email as read by setting the Seen flag.')]
    public function readMail(string $mailbox, string $folder, string $id): bool
    {
        $this->validateInput('readMail', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $sourceFolder = self::findFolderOrFail($client, $folder);
            $message = self::findMessageByMessageId($sourceFolder, $id);
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }
            $message->setFlag('Seen');
            return true;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Mark an email as unread.
     *
     * Removes the 'Seen' IMAP flag from the specified email, marking it as unread in the mailbox.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $folder Folder containing the email (required, e.g., 'INBOX')
     * @param string $id Message-ID of the email to mark as unread (required)
     * @return bool True if flag was removed successfully
     * @throws \Exception If message ID not found
     */
    #[McpTool(name: 'unread_mail', description: 'Mark an email as unread by removing the Seen flag.')]
    public function unreadMail(string $mailbox, string $folder, string $id): bool
    {
        $this->validateInput('unreadMail', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $sourceFolder = self::findFolderOrFail($client, $folder);
            $message = self::findMessageByMessageId($sourceFolder, $id);
            if (!$message) {
                throw new \Exception('Message id not found: ' . $id);
            }
            $message->unsetFlag('Seen');
            return true;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Get mailbox quota information.
     *
     * Returns usage, limit and ratio values from the IMAP STORAGE quota.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     */
    #[McpTool(name: 'quota', description: 'Get mailbox quota information. Returns usage, limit and ratio.')]
    public function quota(string $mailbox): array
    {
        $this->validateInput('quota', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $quota = $client->getQuotaRoot('INBOX');
            $items = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($quota));
            $values = iterator_to_array($items, false);
            foreach ($values as $index => $value) {
                if (mb_strtoupper((string) $value) !== 'STORAGE') {
                    continue;
                }
                $used = (float) ($values[$index + 1] ?? 0);
                $limit = (float) ($values[$index + 2] ?? 0);
                return ['usage' => $used, 'limit' => $limit, 'ratio' => $limit > 0 ? $used / $limit : 0];
            }
            return ['usage' => 0, 'limit' => 0, 'ratio' => 0];
        } finally {
            $client->disconnect();
        }
    }

    /**
     * List all folders in a mailbox.
     *
     * Returns an envelope with `count` and `items` (folder names). INBOX and
     * its subfolders appear first, remaining folders sorted alphabetically.
     * Folder names are returned as-is (may be mUTF-7 encoded).
     *
     * @param string $mailbox Email address of the mailbox (must exist in config.json).
     */
    #[
        McpTool(
            name: 'get_folders',
            description: 'List all IMAP folders in a mailbox. Returns {count, items} envelope with INBOX sorted first.'
        )
    ]
    public function getFolders(string $mailbox): array
    {
        $this->validateInput('getFolders', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
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

            return ['count' => count($folders), 'items' => $folders];
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Create a new folder in the mailbox.
     *
     * Creates a new IMAP folder. Use '/' as delimiter for nested folders (e.g., 'INBOX/Projects').
     * Folder names with special characters are supported.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $name Name of the folder to create (required, e.g., 'Archive', 'INBOX/Work')
     * @return bool True if folder was created successfully
     * @throws \Exception If folder already exists or creation fails
     */
    #[McpTool(name: 'create_folder', description: 'Create a new IMAP folder. Use / as delimiter for nested folders.')]
    public function createFolder(string $mailbox, string $name): bool
    {
        $this->validateInput('createFolder', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $client->createFolder($name, false);
            return true;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Rename an existing folder.
     *
     * Renames an IMAP folder. Child folders are automatically moved with the parent.
     * Folder names with special characters (umlauts) are automatically encoded to mUTF-7.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $name_old Current folder name (required, e.g., 'OldName')
     * @param string $name_new New folder name (required, e.g., 'NewName')
     * @return bool True if folder was renamed successfully
     * @throws \Exception If folder not found or rename fails
     */
    #[
        McpTool(
            name: 'rename_folder',
            description: 'Rename an existing IMAP folder. Supports special characters in folder names.'
        )
    ]
    public function renameFolder(string $mailbox, string $name_old, string $name_new): bool
    {
        $this->validateInput('renameFolder', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $sourceFolder = self::findFolderOrFail($client, $name_old);
            $client
                ->getConnection()
                ->renameFolder(self::encodeImapUtf7($sourceFolder->full_name), self::encodeImapUtf7($name_new))
                ->validatedData();
            return true;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Delete a folder from the mailbox.
     *
     * Permanently deletes an IMAP folder and all its contents. This action cannot be undone.
     * Protected system folders (INBOX, Sent, Trash) may not be deletable depending on server configuration.
     *
     * @param string $mailbox Email address of the mailbox (required, must exist in config.json)
     * @param string $name Name of the folder to delete (required, e.g., 'OldArchive')
     * @return bool True if folder was deleted successfully
     * @throws \Exception If folder not found or deletion fails
     */
    #[McpTool(name: 'delete_folder', description: 'Permanently delete an IMAP folder and all its contents.')]
    public function deleteFolder(string $mailbox, string $name): bool
    {
        $this->validateInput('deleteFolder', get_defined_vars());
        $settings = $this->setupSettings($mailbox);

        $cm = new ClientManager();
        $client = $cm->make($settings);
        $client->connect();
        try {
            $sourceFolder = self::findFolderOrFail($client, $name);
            $sourceFolder->delete(false);
            return true;
        } finally {
            $client->disconnect();
        }
    }

    /**
     * Get the current mailbox configuration (secrets masked).
     *
     * Returns the parsed configuration from config.json. Values of keys matching
     * password / secret / token / api_key patterns are replaced with '***' so
     * credentials never reach MCP clients or LLM context.
     */
    #[
        McpTool(
            name: 'get_config',
            description: 'Get the mailhelper configuration (mailbox list + IMAP/SMTP settings). Secrets are masked.'
        )
    ]
    public function getConfig(): array
    {
        return self::maskSecrets($this->config);
    }

    // find a single message by Message-ID. tries the fast imap-side search
    // first; if that misses (e.g. because the server's Message-ID index does
    // not contain the requested value due to a malformed-header mail the
    // server itself could not parse), fall back to scanning every message in
    // the folder and matching against the *healed* message_id. the heal cost
    // is only paid for messages whose webklex-extracted message_id is empty.
    private static function findMessageByMessageId(
        \Webklex\PHPIMAP\Folder $folder,
        string $id,
        bool $fetchBody = false
    ): ?\Webklex\PHPIMAP\Message {
        $loadFullMessage = function (\Webklex\PHPIMAP\Message $message) use ($folder, $fetchBody): \Webklex\PHPIMAP\Message {
            if (!$fetchBody) {
                return $message;
            }
            return $folder
                ->query()
                ->whereUid((int) $message->uid)
                ->leaveUnread()
                ->get()
                ->first() ?: $message;
        };
        if (str_starts_with($id, 'uid:')) {
            $query = $folder
                ->query()
                ->whereUid((int) substr($id, 4))
                ->leaveUnread();
            if (!$fetchBody) {
                $query->setFetchBody(false);
            }
            return $query->get()->first();
        }
        $query = $folder->query()->whereMessageId($id)->leaveUnread();
        if (!$fetchBody) {
            $query->setFetchBody(false);
        }
        $message = $query->get()->first();
        if ($message !== null) {
            return $message;
        }
        $messages = $folder->query()->all()->leaveUnread()->setFetchBody(false)->get();
        foreach ($messages as $m) {
            $native = $m->getMessageId()->toString();
            if ($native === $id) {
                return $loadFullMessage($m);
            }
            if ($native !== '') {
                continue;
            }
            $healed = self::healCorruptedHeaders($m);
            if ($healed !== null && ($healed['message_id'] ?? '') === $id) {
                return $loadFullMessage($m);
            }
        }
        return null;
    }

    // find a folder by full_name (mUTF-7 encoded or decoded) or throw a clear
    // error message that lists the available folders. used by every method that
    // operates on a single folder, so that "folder does not exist" never
    // silently completes with a false-positive return value.
    private static function findFolderOrFail(\Webklex\PHPIMAP\Client $client, string $folder): \Webklex\PHPIMAP\Folder
    {
        $folders = $client->getFolders(false);
        $decoded = [];
        $match = null;
        foreach ($folders as $folders__value) {
            $decodedName = self::decodeImapUtf7($folders__value->full_name);
            $decoded[] = $decodedName;
            if ($folders__value->full_name === $folder || $decodedName === $folder) {
                $match = $folders__value;
            }
        }
        if ($match === null) {
            throw new \Exception(
                'Folder does not exist: "' . $folder . '". Available folders: ' . implode(', ', $decoded)
            );
        }
        return $match;
    }

    // recursively replace values of credential-like keys with '***'. matches any
    // key containing password/secret/token/api_key (case-insensitive). key names
    // are preserved so the LLM still sees which fields exist.
    private static function maskSecrets(array $data): array
    {
        $sensitivePattern = '/(password|secret|token|api[_-]?key|authorization)/i';
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::maskSecrets($value);
                continue;
            }
            if (is_string($key) && preg_match($sensitivePattern, $key) === 1 && $value !== null && $value !== '') {
                $result[$key] = '***';
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function loadConfig(): array
    {
        $configPath = self::getBasePath() . '/config.json';
        if (!file_exists($configPath)) {
            throw new \Exception('Configuration file not found: ' . $configPath);
        }
        $configContent = file_get_contents($configPath);
        $json = json_decode($configContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Error decoding configuration file: ' . json_last_error_msg());
        }
        return $json;
    }

    private static function getBasePath(): string
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

    public static function parseCli(): void
    {
        $args = $_SERVER['argv'];

        // parse action
        $action = $args[1] ?? null;
        $actions = [
            'fetch-mails',
            'send-mail',
            'save-draft',
            'send-draft',
            'view-mail',
            'move-mail',
            'delete-mail',
            'read-mail',
            'unread-mail',
            'get-folders',
            'create-folder',
            'rename-folder',
            'delete-folder',
            'quota',
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
        if (isset($options['filter_date_from'])) {
            $filter['date_from'] = $options['filter_date_from'];
        }
        if (isset($options['filter_date_until'])) {
            $filter['date_until'] = $options['filter_date_until'];
        }
        if (isset($options['filter_subject'])) {
            $filter['subject'] = $options['filter_subject'];
        }
        if (isset($options['filter_content'])) {
            $filter['content'] = $options['filter_content'];
        }
        if (isset($options['filter_from'])) {
            $filter['from'] = $options['filter_from'];
        }
        if (isset($options['filter_to'])) {
            $filter['to'] = $options['filter_to'];
        }
        if (isset($options['filter_cc'])) {
            $filter['cc'] = $options['filter_cc'];
        }

        // parse json
        $to = isset($options['to']) ? json_decode($options['to'], true) ?? $options['to'] : null;
        $cc = isset($options['cc']) ? json_decode($options['cc'], true) ?? $options['cc'] : null;
        $bcc = isset($options['bcc']) ? json_decode($options['bcc'], true) ?? $options['bcc'] : null;
        $attachments = isset($options['attachments'])
            ? json_decode($options['attachments'], true) ?? $options['attachments']
            : null;

        $mailhelper = new mailhelper();

        try {
            $response = null;
            if ($action === 'fetch-mails') {
                $response = $mailhelper->fetchMails(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    filter: !empty($filter) ? $filter : null,
                    limit: isset($options['limit']) ? (int) $options['limit'] : 50,
                    order: $options['order'] ?? null,
                    progress: ($options['progress'] ?? false) === true ||
                        in_array(mb_strtolower((string) ($options['progress'] ?? '')), ['1', 'true', 'yes', 'on'], true)
                );
            }

            if ($action === 'send-mail') {
                $response = $mailhelper->sendMail(
                    mailbox: $options['mailbox'] ?? null,
                    subject: $options['subject'] ?? null,
                    content: $options['content'] ?? null,
                    to: $to ?? null,
                    cc: $cc ?? null,
                    bcc: $bcc ?? null,
                    from_name: $options['from_name'] ?? null,
                    attachments: $attachments ?? null,
                    content_plain: $options['content_plain'] ?? null
                );
            }

            if ($action === 'save-draft') {
                $response = $mailhelper->saveDraft(
                    mailbox: $options['mailbox'] ?? null,
                    subject: $options['subject'] ?? null,
                    content: $options['content'] ?? null,
                    to: $to ?? null,
                    cc: $cc ?? null,
                    bcc: $bcc ?? null,
                    from_name: $options['from_name'] ?? null,
                    attachments: $attachments ?? null,
                    content_plain: $options['content_plain'] ?? null
                );
            }

            if ($action === 'send-draft') {
                $response = $mailhelper->sendDraft(
                    mailbox: $options['mailbox'] ?? null,
                    id: $options['id'] ?? null
                );
            }

            if ($action === 'view-mail') {
                $response = $mailhelper->viewMail(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    id: $options['id'] ?? null,
                    // bare boolean flag — presence means true, absence means false
                    inline_files: isset($options['inline_files'])
                );
            }

            if ($action === 'move-mail') {
                $response = $mailhelper->moveMail(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    id: $options['id'] ?? null,
                    name: $options['name'] ?? null
                );
            }

            if ($action === 'delete-mail') {
                $response = $mailhelper->deleteMail(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    id: $options['id'] ?? null
                );
            }

            if ($action === 'read-mail') {
                $response = $mailhelper->readMail(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    id: $options['id'] ?? null
                );
            }

            if ($action === 'unread-mail') {
                $response = $mailhelper->unreadMail(
                    mailbox: $options['mailbox'] ?? null,
                    folder: $options['folder'] ?? null,
                    id: $options['id'] ?? null
                );
            }

            if ($action === 'get-folders') {
                $response = $mailhelper->getFolders(mailbox: $options['mailbox'] ?? null);
            }

            if ($action === 'quota') {
                $response = $mailhelper->quota(mailbox: $options['mailbox'] ?? null);
            }

            if ($action === 'create-folder') {
                $response = $mailhelper->createFolder(
                    mailbox: $options['mailbox'] ?? null,
                    name: $options['name'] ?? null
                );
            }

            if ($action === 'rename-folder') {
                $response = $mailhelper->renameFolder(
                    mailbox: $options['mailbox'] ?? null,
                    name_old: $options['name_old'] ?? null,
                    name_new: $options['name_new'] ?? null
                );
            }

            if ($action === 'delete-folder') {
                $response = $mailhelper->deleteFolder(
                    mailbox: $options['mailbox'] ?? null,
                    name: $options['name'] ?? null
                );
            }

            if ($action === 'get-config') {
                $response = $mailhelper->getConfig();
            }

            if ($response !== null) {
                $response = self::prettyPrint($response);
                echo $response;
                die();
            }
        } catch (\Throwable $e) {
            echo '⛔ ';
            echo $e->getMessage() . "\n";
            die();
        }
    }

    public static function isCli(): bool
    {
        return php_sapi_name() === 'cli' &&
            isset($_SERVER['argv']) &&
            !in_array(basename($_SERVER['argv'][0]), ['phpunit', 'mcp-server.php']);
    }

    private static function prettyPrint(mixed $data, int $indent = 0): string
    {
        if ($data === true) {
            return '✅' . "\n";
        }
        if ($data === false) {
            return '⛔' . "\n";
        }
        $output = '';
        $prefix = str_repeat('  ', $indent);

        if (is_object($data)) {
            $data = (array) $data;
        }
        if (is_array($data)) {
            if (empty($data)) {
                $output .= $prefix . '[]' . "\n";
            } else {
                $is_assoc = array_keys($data) !== range(0, count($data) - 1);
                foreach ($data as $key => $value) {
                    if (is_object($value)) {
                        $value = (array) $value;
                    }
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

    private static function prettyPrintValue(mixed $value): mixed
    {
        if (is_array($value) && empty($value)) {
            return '[]';
        }
        if (is_object($value) && empty((array) $value)) {
            return '{}';
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
            return serialize($value);
        }
        // replace newlines
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $maxLength = 50;
        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength) . '...';
        }
        return $value;
    }

    private static function parseJsonParam(string|array|null $value): string|array|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $value; // Return original string if not valid JSON
        }
        return $decoded;
    }

    private static function progress(
        int $done,
        int $total,
        string $info = '',
        int $width = 75,
        string $char = '='
    ): void {
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

    private function validateInput(string $action, array $args): void
    {
        if (!($args['mailbox'] ?? null)) {
            throw new \Exception('Missing mailbox.');
        }
        if (!isset($this->config[$args['mailbox']])) {
            throw new \Exception('Mailbox not found in configuration: ' . $args['mailbox']);
        }
        $needs_smtp = in_array($action, ['sendMail', 'saveDraft', 'sendDraft'], true);
        $needs_imap = $action !== 'sendMail';
        if ($needs_smtp && !isset($this->config[$args['mailbox']]['smtp'])) {
            throw new \Exception('Missing SMTP configuration for mailbox: ' . $args['mailbox']);
        }
        if ($needs_imap && !isset($this->config[$args['mailbox']]['imap'])) {
            throw new \Exception('Missing IMAP configuration for mailbox: ' . $args['mailbox']);
        }
        if ($action === 'sendMail' || $action === 'saveDraft') {
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
        if ($action === 'sendDraft') {
            if (!($args['id'] ?? null)) {
                throw new \Exception('Missing id.');
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
            if (!($args['name'] ?? null)) {
                throw new \Exception('Missing target folder name.');
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

    private function setupSettings(string $mailbox): array
    {
        $settings = [];
        if ($this->config[$mailbox]['imap']['tenant_id'] ?? null) {
            $settings = [
                'protocol' => 'imap',
                'host' => $this->config[$mailbox]['imap']['host'] ?? null,
                'port' => $this->config[$mailbox]['imap']['port'] ?? null,
                'username' => $mailbox,
                'password' => $this->getMicrosoftOAuthToken(
                    $this->config[$mailbox]['imap']['tenant_id'],
                    $this->config[$mailbox]['imap']['client_id'],
                    $this->config[$mailbox]['imap']['client_secret']
                ),
                'authentication' => 'oauth',
                'encryption' => $this->config[$mailbox]['imap']['encryption'] ?? null,
                'validate_cert' => true
            ];
        } else {
            $settings = [
                'protocol' => 'imap',
                'host' => $this->config[$mailbox]['imap']['host'] ?? null,
                'port' => $this->config[$mailbox]['imap']['port'] ?? null,
                'username' => $this->config[$mailbox]['imap']['username'] ?? null,
                'password' => $this->config[$mailbox]['imap']['password'] ?? null,
                'authentication' => null,
                'encryption' => $this->config[$mailbox]['imap']['encryption'] ?? null,
                'validate_cert' => false
            ];
        }
        return $settings;
    }

    private function getMicrosoftOAuthToken(string $tenantId, string $clientId, string $clientSecret): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://login.microsoftonline.com/' . $tenantId . '/oauth2/v2.0/token');
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://outlook.office365.com/.default',
                'grant_type' => 'client_credentials'
            ])
        );
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $curl_result = curl_exec($ch);

        if (empty($curl_result)) {
            throw new \Exception('OAuth: token request failed (empty response).');
        }

        $curl_result = json_decode($curl_result, true);
        if (empty($curl_result)) {
            throw new \Exception('OAuth: invalid JSON response.');
        }

        if (isset($curl_result['error'])) {
            throw new \Exception('OAuth: ' . ($curl_result['error_description'] ?? $curl_result['error']));
        }

        if (!isset($curl_result['access_token'])) {
            throw new \Exception('OAuth: no access_token in response.');
        }

        return $curl_result['access_token'];
    }

    private static function getMailDataBasic(mixed $message): object
    {
        $mail = (object) [];
        $mail->uid = (string) $message->uid;
        $mail->id = $message->getMessageId()->toString();

        $healed = $mail->id === '' ? self::healCorruptedHeaders($message) : null;
        if ($healed !== null && ($healed['message_id'] ?? '') !== '') {
            $mail->id = $healed['message_id'];
        }

        foreach (['from' => 'getFrom', 'to' => 'getTo', 'cc' => 'getCc', 'bcc' => 'getBcc'] as $fields__key => $fields__value) {
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
        if ($healed !== null && !empty($healed['date'])) {
            try {
                $dt = new \DateTime($healed['date']);
                $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                $mail->date = $dt->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
            }
        }

        $subject = preg_replace("/\r\n|\r|\n/", '', trim((string) ($message->getSubject()[0] ?? '')));
        if ($healed !== null && !empty($healed['subject'])) {
            $subject = $healed['subject'];
        }
        if (mb_detect_encoding($subject, 'UTF-8, ISO-8859-1') !== 'UTF-8') {
            $subject = self::utf8EncodeLegacy($subject);
        }
        $mail->subject = $subject;

        $flags = $message->getFlags()->toArray();
        $mail->seen = !empty($flags) && in_array('Seen', $flags);

        return $mail;
    }

    private static function healCorruptedHeaders(mixed $message): ?array
    {
        try {
            $raw_header = $message->getHeader()->raw ?? '';
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_string($raw_header) || $raw_header === '') {
            return null;
        }
        try {
            $raw_body = $message->getRawBody();
            if ($raw_body === '') {
                $message->parseBody();
                $raw_body = $message->getRawBody();
            }
        } catch (\Throwable $e) {
            $raw_body = '';
        }
        if (!is_string($raw_body)) {
            $raw_body = '';
        }
        $raw_eml = rtrim($raw_header, "\r\n") . "\r\n\r\n" . ltrim($raw_body, "\r\n");
        $fixed = preg_replace('/\r\n\r\n(\?=)/', "\r\n \\1", $raw_eml, 1);
        if ($fixed === null || $fixed === $raw_eml) {
            return null;
        }
        $sep_pos = strpos($fixed, "\r\n\r\n");
        if ($sep_pos === false) {
            return null;
        }
        $header_block = substr($fixed, 0, $sep_pos);
        $result = ['message_id' => '', 'date' => '', 'subject' => ''];
        if (preg_match('/^Message-ID:\s*<?([^>\r\n]+?)>?\s*(?:\r?\n|$)/im', $header_block, $m)) {
            $result['message_id'] = trim($m[1]);
        }
        if (preg_match('/^Date:\s*([^\r\n]+)/im', $header_block, $m)) {
            $result['date'] = trim($m[1]);
        }
        if (preg_match('/^Subject:((?:[^\r\n]|\r?\n[ \t])+)/im', $header_block, $m)) {
            $subject = preg_replace('/\r?\n[ \t]+/', ' ', trim($m[1]));
            $decoded = @mb_decode_mimeheader($subject);
            $result['subject'] = is_string($decoded) && $decoded !== '' ? $decoded : $subject;
        }
        return $result;
    }

    private static function checkAndFormatAttachment(string $attachment): string
    {
        if (!file_exists($attachment)) {
            $attachment = self::getBasePath() . '/' . ltrim($attachment, '/');
        }
        if (!file_exists($attachment)) {
            throw new \Exception('Attachment file not found: ' . $attachment);
        }
        return $attachment;
    }

    private static function utf8EncodeLegacy(string $str): string
    {
        return \UConverter::transcode($str, 'UTF8', 'ISO-8859-1');
    }

    public static function encodeImapUtf7(string $string): string
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

    public static function decodeImapUtf7(string $string): string
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

if (mailhelper::isCli() && basename((string) ($_SERVER['argv'][0] ?? '')) === 'mailhelper.php') {
    mailhelper::parseCli();
}
