<?php
declare(strict_types=1);
use vielhuber\mailhelper\mailhelper;

class Test extends \PHPUnit\Framework\TestCase
{
    protected int $sleep = 10;
    protected mailhelper $mailhelper;
    protected array $mailboxes = [];

    protected function setUp(): void
    {
        $this->sleep = ($_SERVER['CI'] ?? '') === 'true' ? 20 : 10;

        $this->mailhelper = new mailhelper();

        $config = $this->mailhelper->getConfig();
        foreach ($config as $config__key => $config__value) {
            if (($config__value['test'] ?? null) !== false) {
                $this->mailboxes[] = $config__key;
            }
        }
    }

    public function test__rejects_numeric_message_id(): void
    {
        $mailhelper = new mailhelper(['test@example.com' => ['imap' => []]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('bare numeric values are ambiguous');
        $mailhelper->viewMail(mailbox: 'test@example.com', folder: 'INBOX', id: '137');
    }

    public function test__fetch_mails_searches_all_folders_by_default(): void
    {
        $folder = (new \ReflectionMethod(mailhelper::class, 'fetchMails'))->getParameters()[1];

        $this->assertTrue($folder->getType()?->allowsNull());
        $this->assertTrue($folder->isDefaultValueAvailable());
        $this->assertNull($folder->getDefaultValue());
    }

    public function test__attachment_tool_schema_accepts_strings_and_arrays(): void
    {
        $schemaGenerator = new \PhpMcp\Server\Utils\SchemaGenerator(new \PhpMcp\Server\Utils\DocBlockParser());
        foreach (['sendMail', 'saveDraft'] as $methodName) {
            $schema = $schemaGenerator->generate(new \ReflectionMethod(mailhelper::class, $methodName));
            $this->assertSame(['string', 'array', 'null'], $schema['properties']['attachments']['type']);
            $this->assertSame(['file'], $schema['properties']['attachments']['items']['oneOf'][1]['required']);
        }
    }

    public function test__accepts_prefixed_uid(): void
    {
        $mailhelper = new mailhelper(['test@example.com' => ['imap' => []]]);
        $method = new \ReflectionMethod($mailhelper, 'validateInput');
        $method->invoke($mailhelper, 'viewMail', [
            'mailbox' => 'test@example.com',
            'id' => 'uid:137'
        ]);
        $this->addToAssertionCount(1);
    }

    public function test__mail_data_exposes_uid_and_message_id(): void
    {
        $message = \Webklex\PHPIMAP\Message::fromString(
            "From: Test <test@example.com>\r\n" .
                "To: Demo <demo@example.com>\r\n" .
                "Date: Thu, 16 Jul 2026 12:00:00 +0200\r\n" .
                "Subject: Test\r\n" .
                "Message-ID: <abc@example.com>\r\n\r\nBody"
        );
        $message->setUid(137);
        $method = new \ReflectionMethod(mailhelper::class, 'getMailDataBasic');
        $mail = $method->invoke(null, $message);

        $this->assertSame('137', $mail->uid);
        $this->assertSame('abc@example.com', $mail->id);
    }

    public function test__mail_data_decodes_mime_subject(): void
    {
        $message = \Webklex\PHPIMAP\Message::fromString(
            "From: Test <test@example.com>\r\n" .
                "To: Demo <demo@example.com>\r\n" .
                "Date: Thu, 16 Jul 2026 12:00:00 +0200\r\n" .
                "Subject: =?utf-8?Q?Ein_kleiner_Gru=C3=9F_in_Versen?=\r\n" .
                "Message-ID: <unicode@example.com>\r\n\r\nBody"
        );
        $message->setUid(138);
        $method = new \ReflectionMethod(mailhelper::class, 'getMailDataBasic');
        $mail = $method->invoke(null, $message);

        $this->assertSame('Ein kleiner Gruß in Versen', $mail->subject);
    }

    public function test__unicode_search_uses_server_prefilter_and_local_exact_match(): void
    {
        $prepare = new \ReflectionMethod(mailhelper::class, 'prepareSearchFilters');
        [$serverFilters, $localFilters] = $prepare->invoke(null, ['subject' => 'Ein kleiner Gruß in Versen']);

        $this->assertSame('Ein kleiner Gru', $serverFilters['subject']);
        $this->assertSame(['subject' => 'Ein kleiner Gruß in Versen'], $localFilters);

        $matches = new \ReflectionMethod(mailhelper::class, 'matchesSearchFilters');
        $this->assertTrue(
            $matches->invoke(null, (object) ['subject' => 'Re: Ein kleiner Gruß in Versen'], new \stdClass(), $localFilters)
        );
        $this->assertFalse(
            $matches->invoke(null, (object) ['subject' => 'Anderer Betreff'], new \stdClass(), $localFilters)
        );
    }

    public function test__message_id_lookup_ignores_partial_imap_match(): void
    {
        $partialMessage = \Webklex\PHPIMAP\Message::fromString(
            "Date: Thu, 16 Jul 2026 12:00:00 +0200\r\n" .
                "Message-ID: <prefix-target@example.com-suffix>\r\n\r\nPartial"
        );
        $exactMessage = \Webklex\PHPIMAP\Message::fromString(
            "Date: Thu, 16 Jul 2026 12:00:00 +0200\r\n" .
                "Message-ID: <target@example.com>\r\n\r\nExact"
        );
        $fastQuery = $this->createStub(\Webklex\PHPIMAP\Query\WhereQuery::class);
        $fastQuery->method('whereMessageId')->willReturnSelf();
        $fastQuery->method('leaveUnread')->willReturnSelf();
        $fastQuery->method('setFetchBody')->willReturnSelf();
        $fastQuery->method('get')->willReturn(
            new \Webklex\PHPIMAP\Support\MessageCollection([$partialMessage])
        );
        $scanQuery = $this->createStub(\Webklex\PHPIMAP\Query\WhereQuery::class);
        $scanQuery->method('__call')->willReturnSelf();
        $scanQuery->method('leaveUnread')->willReturnSelf();
        $scanQuery->method('setFetchBody')->willReturnSelf();
        $scanQuery->method('get')->willReturn(
            new \Webklex\PHPIMAP\Support\MessageCollection([$partialMessage, $exactMessage])
        );
        $folder = new class($fastQuery, $scanQuery) extends \Webklex\PHPIMAP\Folder {
            private array $queries;

            public function __construct(
                \Webklex\PHPIMAP\Query\WhereQuery $fastQuery,
                \Webklex\PHPIMAP\Query\WhereQuery $scanQuery
            ) {
                $this->queries = [$fastQuery, $scanQuery];
            }

            public function query(array $extensions = []): \Webklex\PHPIMAP\Query\WhereQuery
            {
                return array_shift($this->queries);
            }
        };
        $method = new \ReflectionMethod(mailhelper::class, 'findMessageByMessageId');
        $message = $method->invoke(null, $folder, 'target@example.com');

        $this->assertSame($exactMessage, $message);
    }

    public function test__folders()
    {
        foreach ($this->mailboxes as $mailboxes__value) {
            // getFolders
            $response = $this->mailhelper->getFolders(mailbox: $mailboxes__value);
            $this->assertGreaterThan(0, $response['count']);
            $this->assertSame($response['count'], count($response['items']));

            // createFolder
            $prefix = $this->determinePrefix($mailboxes__value);
            $folder_old = $prefix . 'Testüüü Folder ' . mt_rand(1000, 9999);
            $folder_new = $prefix . 'Renamedääää Test Folder ' . mt_rand(1000, 9999);
            try {
                $this->mailhelper->deleteFolder(mailbox: $mailboxes__value, name: $folder_old);
                $this->mailhelper->deleteFolder(mailbox: $mailboxes__value, name: $folder_new);
            } catch (\Throwable $e) {
            }
            $response = $this->mailhelper->createFolder(mailbox: $mailboxes__value, name: $folder_old);
            $this->assertTrue($response);

            $this->sleep();

            $response = $this->mailhelper->getFolders(mailbox: $mailboxes__value);
            $this->assertGreaterThan(0, $response['count']);
            $this->assertContains($folder_old, $response['items']);

            // renameFolder
            $response = $this->mailhelper->renameFolder(
                mailbox: $mailboxes__value,
                name_old: $folder_old,
                name_new: $folder_new
            );
            $this->assertTrue($response);

            $this->sleep();

            $response = $this->mailhelper->getFolders(mailbox: $mailboxes__value);
            $this->assertContains($folder_new, $response['items']);
            $this->assertNotContains($folder_old, $response['items']);

            $this->sleep();

            // deleteFolder
            $response = $this->mailhelper->deleteFolder(mailbox: $mailboxes__value, name: $folder_new);
            $this->assertTrue($response);
            $this->sleep();
            $response = $this->mailhelper->getFolders(mailbox: $mailboxes__value);
            $this->assertNotContains($folder_new, $response['items']);
            $this->assertNotContains($folder_old, $response['items']);
        }
    }

    public function test__mail()
    {
        foreach ($this->mailboxes as $mailboxes__value) {
            [$folder_inbox, $folder_other] = $this->determineFolders($mailboxes__value);
            if ($folder_inbox === null || $folder_other === null) {
                $this->fail('No inbox/other folder found.');
            }
            //$this->log($this->mailhelper->getFolders(mailbox: $mailboxes__value));

            // saveDraft/sendDraft
            $draft_subject = 'DRAFT TEST 🚧 ' . mt_rand(1000, 9999);
            $draft_content = '✏️ Draft <strong>test</strong>! ' . mt_rand(1000, 9999);
            $response = $this->mailhelper->saveDraft(
                mailbox: $mailboxes__value,
                subject: $draft_subject,
                content: $draft_content,
                to: $mailboxes__value,
                from_name: 'John Doee'
            );
            $this->assertTrue($response);
            $this->sleep();
            $drafts_folder = $this->determineDraftsFolder($mailboxes__value);
            $this->assertNotNull($drafts_folder, 'Drafts folder could not be determined');
            $response = $this->mailhelper->fetchMails(
                mailbox: $mailboxes__value,
                folder: $drafts_folder,
                filter: ['subject' => 'DRAFT TEST'],
                limit: 50,
                order: 'desc'
            );
            $draft_id = null;
            foreach ($response['items'] as $items__value) {
                if ($items__value->subject === $draft_subject) {
                    $draft_id = $items__value->id;
                    break;
                }
            }
            $this->assertNotNull($draft_id, 'Saved draft not found in ' . $drafts_folder);
            $this->sleep();
            $response = $this->mailhelper->sendDraft(mailbox: $mailboxes__value, id: $draft_id);
            $this->assertTrue($response);
            $this->sleep();
            $response = $this->mailhelper->fetchMails(
                mailbox: $mailboxes__value,
                folder: $drafts_folder,
                filter: ['subject' => 'DRAFT TEST'],
                limit: 50,
                order: 'desc'
            );
            $still_there = false;
            foreach ($response['items'] as $items__value) {
                if ($items__value->subject === $draft_subject) {
                    $still_there = true;
                    break;
                }
            }
            $this->assertFalse($still_there, 'Draft still present in ' . $drafts_folder . ' after sendDraft');
            $response = $this->mailhelper->fetchMails(
                mailbox: $mailboxes__value,
                folder: $folder_inbox,
                filter: ['subject' => 'DRAFT TEST'],
                limit: 50,
                order: 'desc'
            );
            $delivered_id = null;
            foreach ($response['items'] as $items__value) {
                if ($items__value->subject === $draft_subject) {
                    $delivered_id = $items__value->id;
                    break;
                }
            }
            $this->assertNotNull($delivered_id, 'Sent draft did not arrive in ' . $folder_inbox);
            $response = $this->mailhelper->deleteMail(
                mailbox: $mailboxes__value,
                folder: $folder_inbox,
                id: $delivered_id
            );
            $this->assertTrue($response);
            $this->sleep();

            $test_subject = 'JOOOOOO This is a test! 🚀 ' . mt_rand(1000, 9999);
            $test_content =
                '✅ Test <strong>successful</strong>! ' .
                mt_rand(1000, 9999) .
                '<br><img src="' .
                __DIR__ .
                '/test.jpg" alt="Test">';

            // sendMail
            $response = $this->mailhelper->sendMail(
                mailbox: $mailboxes__value,
                subject: $test_subject,
                content: $test_content,
                to: [['name' => 'John Doe', 'email' => $mailboxes__value]],
                cc: 'test_cc@mailinator.com',
                bcc: 'test_bcc@mailinator.com',
                from_name: 'John Doee',
                attachments: [['name' => 'foo.jpg', 'file' => __DIR__ . '/test.jpg']]
            );
            //$this->log($response);
            $this->assertTrue($response);

            $this->sleep();

            // fetchMails
            $response = $this->mailhelper->fetchMails(
                mailbox: $mailboxes__value,
                folder: $folder_inbox,
                filter: [
                    'date_from' => date('Y-m-d', strtotime('now - 1 day')),
                    'subject' => 'JOOOOOO This is a test!'
                ],
                limit: 10, // don't limit 10, because other mails can income that disturb the test
                order: 'desc'
            );
            $this->assertGreaterThan(0, $response['count']);
            $this->assertSame($response['count'], count($response['items']));
            $mail_id = null;
            foreach ($response['items'] as $response__value) {
                if ($response__value->subject === $test_subject) {
                    $mail_id = $response__value->id;
                    break;
                }
            }
            $this->assertNotNull($mail_id);

            // viewMail (default: eml and attachments are written to disk, paths returned)
            $response = $this->mailhelper->viewMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            //$this->log($response);
            $this->assertSame($response->id, $mail_id);
            $this->assertSame($response->subject, $test_subject);
            $this->assertStringContainsString('✅ Test <strong>successful</strong>!', $response->content_html);
            $this->assertMatchesRegularExpression(
                '/<img src="' . preg_quote(sys_get_temp_dir(), '/') . '\/mailhelper-output\/[^"]+" alt="Test">/',
                $response->content_html
            );
            $this->assertStringNotContainsString(__DIR__ . '/test.jpg', $response->content_html);
            $this->assertStringContainsString(strip_tags($test_content), $response->content_plain);
            $this->assertIsString($response->eml);
            $this->assertFileExists($response->eml);
            $this->assertNotEmpty($response->attachments);
            $this->assertObjectNotHasProperty('content', $response->attachments[0]);
            $this->assertIsString($response->attachments[0]->path);
            $this->assertFileExists($response->attachments[0]->path);
            $this->assertNotNull($response->attachments[0]->mime_type);
            $this->assertIsInt($response->attachments[0]->size);
            $this->assertGreaterThan(0, $response->attachments[0]->size);
            $this->sleep();

            // viewMail with inline_files=true (eml as data-URI, attachments include both path and content)
            $response = $this->mailhelper->viewMail(
                mailbox: $mailboxes__value,
                folder: $folder_inbox,
                id: $mail_id,
                inline_files: true
            );
            $this->assertIsString($response->eml);
            $this->assertStringStartsWith('data:message/rfc822;base64,', $response->eml);
            $this->assertNotEmpty($response->attachments);
            $this->assertIsString($response->attachments[0]->path);
            $this->assertFileExists($response->attachments[0]->path);
            $this->assertNotNull($response->attachments[0]->content);
            $this->assertStringStartsWith('data:', $response->attachments[0]->content);
            $this->sleep();

            // readMail
            $response = $this->mailhelper->readMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $this->assertTrue($response);
            $this->sleep();
            $response = $this->mailhelper->viewMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $this->assertSame($response->seen, true);

            // unreadMail
            $response = $this->mailhelper->unreadMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $this->assertTrue($response);
            $this->sleep();
            $response = $this->mailhelper->viewMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $this->assertSame($response->seen, false);

            // moveMail
            $response = $this->mailhelper->moveMail(
                mailbox: $mailboxes__value,
                folder: $folder_inbox,
                id: $mail_id,
                name: $folder_other
            );
            $this->assertTrue($response);
            $this->sleep();
            $this->expectException(\Throwable::class);
            $this->expectExceptionMessageMatches('/not found/i');
            $response = $this->mailhelper->viewMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $response = $this->mailhelper->viewMail(mailbox: $mailboxes__value, folder: $folder_other, id: $mail_id);
            $this->assertSame($response->id, $mail_id);

            // deleteMail
            $response = $this->mailhelper->deleteMail(mailbox: $mailboxes__value, folder: $folder_other, id: $mail_id);
            $this->assertTrue($response);
            $this->sleep();

            $this->expectException(\Throwable::class);
            $this->expectExceptionMessageMatches('/not found/i');
            $response = $this->mailhelper->viewMail(mailbox: $mailboxes__value, folder: $folder_other, id: $mail_id);
        }
    }

    private function determinePrefix(string $mailbox): string
    {
        $response = $this->mailhelper->getFolders($mailbox);
        $prefix = 'INBOX.';
        if (count(array_filter($response['items'], fn(string $folder): bool => str_starts_with($folder, 'INBOX/'))) > 0) {
            $prefix = 'INBOX/';
        }
        return $prefix;
    }

    private function determineFolders(string $mailbox): array
    {
        $folders = $this->mailhelper->getFolders(mailbox: $mailbox)['items'];
        $folder_inbox = null;
        foreach (['INBOX', 'Inbox', 'Posteingang'] as $folder_inbox__value) {
            if (count(array_filter($folders, fn($folders__value) => $folders__value === $folder_inbox__value)) > 0) {
                $folder_inbox = $folder_inbox__value;
                break;
            }
        }
        $folder_other = null;
        if ($folder_inbox !== null) {
            foreach ($folders as $folders__value) {
                if ($folders__value !== $folder_inbox) {
                    $folder_other = $folders__value;
                    break;
                }
            }
        }
        return [$folder_inbox, $folder_other];
    }

    private function determineDraftsFolder(string $mailbox): ?string
    {
        $folders = $this->mailhelper->getFolders(mailbox: $mailbox)['items'];
        $candidates = [
            'INBOX.Drafts',
            'INBOX/Drafts',
            'INBOX.Entwürfe',
            'INBOX/Entwürfe',
            '[Gmail]/Drafts',
            '[Gmail]/Entwürfe',
            'Drafts',
            'Entwürfe',
            'Draft'
        ];
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $folders, true)) {
                return $candidate;
            }
        }
        foreach ($folders as $folder) {
            $needle = mb_strtolower($folder);
            if (
                mb_strpos($needle, 'draft') !== false ||
                mb_strpos($needle, 'entwurf') !== false ||
                mb_strpos($needle, 'entwürf') !== false
            ) {
                return $folder;
            }
        }
        return null;
    }

    private function sleep(): void
    {
        if ($this->sleep > 0) {
            sleep($this->sleep);
        }
    }

    private function log(mixed $message): void
    {
        if (!is_string($message)) {
            $message = serialize($message);
        }
        fwrite(STDERR, print_r($message . PHP_EOL, true));
    }
}
