<?php
namespace vielhuber\excelhelper;

use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Attributes\Schema;
use PHPMailer\PHPMailer\PHPMailer;

class mailhelper
{
    /**
     * Fetch emails.
     *
     * @param int $a The first number
     * @param int $b The second number
     * @return int The sum of the two numbers
     */
    #[McpTool(name: 'fetch_emails')]
    public static function fetch($mailbox = null, $folder = null, $filter = null, $limit = null) {}

    /**
     * Get folders.
     *
     * @param int $a The first number
     * @param int $b The second number
     * @return int The sum of the two numbers
     */
    #[McpTool(name: 'get_folders')]
    public static function getFolders($mailbox = null) {}

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
    ) {}
}
