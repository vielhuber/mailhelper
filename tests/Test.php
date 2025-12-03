<?php
use vielhuber\mailhelper\mailhelper;

class Test extends \PHPUnit\Framework\TestCase
{
    protected $sleep = 10;

    protected $mailboxes = [];

    protected function setUp(): void
    {
        $this->mailboxes = [];
        $config = mailhelper::getConfig();
        foreach ($config as $config__key => $config__value) {
            $this->mailboxes[] = $config__key;
        }
    }

    public function test__folders()
    {
        foreach ($this->mailboxes as $mailboxes__value) {
            // getFolders
            $response = mailhelper::getFolders(mailbox: $mailboxes__value);
            //$this->log($response);
            $this->assertTrue(count($response) > 0);

            // createFolder
            $prefix = $this->determinePrefix($mailboxes__value);
            //$this->log($prefix);
            $folder_old = $prefix . 'Testüüü Folder ' . mt_rand(1000, 9999);
            $folder_new = $prefix . 'Renamedääää Test Folder ' . mt_rand(1000, 9999);
            try {
                mailhelper::deleteFolder(mailbox: $mailboxes__value, name: $folder_old);
                mailhelper::deleteFolder(mailbox: $mailboxes__value, name: $folder_new);
            } catch (\Throwable $e) {
            }
            $response = mailhelper::createFolder(mailbox: $mailboxes__value, name: $folder_old);
            //$this->log($response);
            $this->assertTrue($response);

            $this->sleep();

            $response = mailhelper::getFolders(mailbox: $mailboxes__value);
            //$this->log($response);
            $this->assertTrue(count($response) > 0);
            $this->assertContains($folder_old, $response);

            // renameFolder
            $response = mailhelper::renameFolder(
                mailbox: $mailboxes__value,
                name_old: $folder_old,
                name_new: $folder_new
            );
            //$this->log($response);
            $this->assertTrue($response);

            $this->sleep();

            $response = mailhelper::getFolders(mailbox: $mailboxes__value);
            //$this->log($response);
            $this->assertContains($folder_new, $response);
            $this->assertNotContains($folder_old, $response);

            // deleteFolder
            $response = mailhelper::deleteFolder(mailbox: $mailboxes__value, name: $folder_new);
            //$this->log($response);
            $this->assertTrue($response);
            sleep(3);
            $response = mailhelper::getFolders(mailbox: $mailboxes__value);
            //$this->log($response);
            $this->assertNotContains($folder_new, $response);
            $this->assertNotContains($folder_old, $response);
        }
    }

    public function test__mail()
    {
        foreach ($this->mailboxes as $mailboxes__value) {
            [$folder_inbox, $folder_other] = $this->determineFolders($mailboxes__value);
            if ($folder_inbox === null || $folder_other === null) {
                $this->fail('No inbox/other folder found.');
            }
            //$this->log(mailhelper::getFolders(mailbox: $mailboxes__value));

            $test_subject = 'JOOOOOO This is a test! 🚀 ' . mt_rand(1000, 9999);
            $test_content = '✅ Test <strong>successful</strong>! ' . mt_rand(1000, 9999);

            // sendMail
            $response = mailhelper::sendMail(
                mailbox: $mailboxes__value,
                subject: $test_subject,
                content: $test_content,
                from_name: 'John Doee',
                to: [['name' => 'John Doe', 'email' => $mailboxes__value]],
                cc: 'test_cc@mailinator.com',
                bcc: 'test_bcc@mailinator.com',
                attachments: [['name' => 'foo.jpg', 'file' => __DIR__ . '/test.jpg']]
            );
            //$this->log($response);
            $this->assertTrue($response);

            $this->sleep();

            // fetchMails
            $response = mailhelper::fetchMails(
                mailbox: $mailboxes__value,
                folder: $folder_inbox,
                limit: 10, // don't limit 10, because other mails can income that disturb the test
                order: 'desc'
            );
            //$this->log($response);
            $this->assertTrue(count($response) > 0);
            $mail_id = null;
            foreach ($response as $response__value) {
                if ($response__value->subject === $test_subject) {
                    $mail_id = $response__value->id;
                    break;
                }
            }

            // viewMail
            $response = mailhelper::viewMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            //$this->log($response);
            $this->assertSame($response->id, $mail_id);
            $this->assertSame($response->subject, $test_subject);
            $this->assertSame($response->content_html, $test_content);
            $this->assertSame($response->content_plain, strip_tags($test_content));
            $this->sleep();

            // readMail
            $response = mailhelper::readMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $this->assertTrue($response);
            $this->sleep();
            $response = mailhelper::viewMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $this->assertSame($response->seen, true);

            // unreadMail
            $response = mailhelper::unreadMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $this->assertTrue($response);
            $this->sleep();
            $response = mailhelper::viewMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $this->assertSame($response->seen, false);

            // moveMail
            $response = mailhelper::moveMail(
                mailbox: $mailboxes__value,
                folder: $folder_inbox,
                id: $mail_id,
                name: $folder_other
            );
            $this->assertTrue($response);
            $this->sleep();
            $this->expectException(\Throwable::class);
            $this->expectExceptionMessageMatches('/not found/i');
            $response = mailhelper::viewMail(mailbox: $mailboxes__value, folder: $folder_inbox, id: $mail_id);
            $response = mailhelper::viewMail(mailbox: $mailboxes__value, folder: $folder_other, id: $mail_id);
            $this->assertSame($response->id, $mail_id);

            // deleteMail
            $response = mailhelper::deleteMail(
                mailbox: $mailboxes__value,
                folder: $folder_other,
                id: $mail_id,
                delete: true
            );
            //$this->log($response);
            $this->assertTrue($response);
            $this->sleep();

            $this->expectException(\Throwable::class);
            $this->expectExceptionMessageMatches('/not found/i');
            $response = mailhelper::viewMail(mailbox: $mailboxes__value, folder: $folder_other, id: $mail_id);
        }
    }

    private function determinePrefix($mailbox): string
    {
        $response = mailhelper::getFolders($mailbox);
        $prefix = 'INBOX.';
        if (count(array_filter($response, fn($f) => str_starts_with($f, 'INBOX/'))) > 0) {
            $prefix = 'INBOX/';
        }
        return $prefix;
    }

    private function determineFolders($mailbox): array
    {
        $folders = mailhelper::getFolders(mailbox: $mailbox);
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

    private function sleep()
    {
        if ($this->sleep > 0) {
            sleep($this->sleep);
        }
    }

    private function log($msg)
    {
        if (!is_string($msg)) {
            $msg = serialize($msg);
        }
        fwrite(STDERR, print_r($msg . PHP_EOL, true));
    }
}
