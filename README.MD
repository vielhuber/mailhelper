[![build status](https://github.com/vielhuber/mailhelper/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/mailhelper/actions)

# 📫 mailhelper 📫

mailhelper is a lightweight email integration layer on top of [phpmailer](https://github.com/PHPMailer/PHPMailer) and [php-imap](https://github.com/Webklex/php-imap) that provides a simple cli, php wrapper, and mcp server for fetching messages, sending emails, and wiring email functionality into existing tooling without having to deal with complex imap/smtp configuration yourself.

## requirements

- php >= 8.1

## installation

### composer

```sh
composer require vielhuber/mailhelper
```

### create config

```sh
vi config.json
```

```json
{
    "info@example.com": {
        "imap": {
            "host": "imap.example.com",
            "port": 993,
            "username": "info@example.com",
            "password": "***",
            "encryption": "ssl"
        },
        "smtp": {
            "host": "smtp.example.com",
            "port": 465,
            "username": "info@example.com",
            "password": "***",
            "encryption": "ssl"
        }
    },
    "support@company.com": {
        "imap": {
            "host": "outlook.office365.com",
            "port": 993,
            "client_id": "***",
            "client_secret": "***",
            "tenant_id": "***",
            "encryption": "tls"
        },
        "smtp": {
            "host": "smtp.company.com",
            "port": 465,
            "username": "support@company.com",
            "password": "***",
            "encryption": "ssl"
        }
    }
}
```

### test

`./vendor/bin/phpunit`

## usage

### cli

```sh
./vendor/bin/mailhelper.php fetch-mails \
    --mailbox "xxx@tld.com" \
    --folder "INBOX/foo/bar" \
    --filter-date-from "YYYY-MM-DD HH:II:SS" \
    --filter-date-until "YYYY-MM-DD HH:II:SS" \
    --filter-subject "pattern" \
    --filter-content "pattern" \
    --filter-to "pattern" \
    --filter-cc "pattern" \
    --limit 42 \
    --order "desc"

./vendor/bin/mailhelper.php send-mail \
    --mailbox "xxx@tld.com" \
    --subject "This is a test! 🚀" \
    --content "✅ Test <strong>successful</strong>!" \
    --from-name "John Doe",
    --to "aaa@tld.com" \
    --to '["aaa@tld.com", "bbb@tld.com"]' \
    --to '[{"name": "John Doe", "email": "aaa@tld.com"}, {"name": "Jane Doe", "email": "bbb@tld.com"}]' \
    --cc "aaa@tld.com" \
    --bcc "aaa@tld.com" \
    --attachments: "/test1.jpg" \
    --attachments: '["/test1.jpg", "/test2.jpg"]' \
    --attachments: '[{"name": "foo1.jpg", "file": "/test1.jpg"}, {"name": "foo2.jpg", "file": "/test2.jpg"}]'

./vendor/bin/mailhelper.php view-mail \
    --mailbox "xxx@tld.com" \
    --folder "INBOX/foo/bar" \
    --id "xxxxxxxxxxxxxxxxxxxxxxxxxxxxx"

./vendor/bin/mailhelper.php move-mail \
    --mailbox "xxx@tld.com" \
    --folder "INBOX/foo/bar" \
    --id "xxxxxxxxxxxxxxxxxxxxxxxxxxxxx" \
    --name "INBOX/foo/bar"

./vendor/bin/mailhelper.php delete-mail \
    --mailbox "xxx@tld.com" \
    --folder "INBOX/foo/bar" \
    --id "xxxxxxxxxxxxxxxxxxxxxxxxxxxxx"

./vendor/bin/mailhelper.php read-mail \
    --mailbox "xxx@tld.com" \
    --folder "INBOX/foo/bar" \
    --id "xxxxxxxxxxxxxxxxxxxxxxxxxxxxx"

./vendor/bin/mailhelper.php unread-mail \
    --mailbox "xxx@tld.com" \
    --folder "INBOX/foo/bar" \
    --id "xxxxxxxxxxxxxxxxxxxxxxxxxxxxx"

./vendor/bin/mailhelper.php get-folders \
    --mailbox "xxx@tld.com"

./vendor/bin/mailhelper.php create-folder \
    --mailbox "xxx@tld.com"
    --name "INBOX/foo"

./vendor/bin/mailhelper.php rename-folder \
    --mailbox "xxx@tld.com"
    --name "INBOX/foo"

./vendor/bin/mailhelper.php delete-folder \
    --mailbox "xxx@tld.com"
    --name "INBOX/foo"

./vendor/bin/mailhelper.php get-config
```

### php

```php
require_once __DIR__ . '/vendor/autoload.php';
use vielhuber\mailhelper\mailhelper;

mailhelper::fetchMails(
    mailbox: 'xxx@tld.com',
    folder: 'INBOX/foo/bar',
    filter: [
        'date_from' => date('Y-m-d H:i:s', strtotime('now - 1 hour')),
        'date_until' => date('Y-m-d H:i:s', strtotime('now')),
        'subject' => 'pattern',
        'content' => 'pattern'
        'to' => 'pattern',
        'cc' => 'pattern'
    ],
    limit: 42,
    order: 'asc'
);

mailhelper::sendMail(
    mailbox: 'xxx@tld.com',
    subject: 'This is a test! 🚀',
    content: '✅ Test <strong>successful</strong>!',
    from_name: 'John Doe',
    to: 'aaa@tld.com',
    to: ['aaa@tld.com', 'bbb@tld.com'],
    to: [['name' => 'John Doe', 'email' => 'aaa@tld.com'], ['name' => 'Jane Doe', 'email' => 'bbb@tld.com']],
    cc: 'aaa@tld.com',
    bcc: 'aaa@tld.com',
    attachments: __DIR__ . '/test1.jpg',
    attachments: [__DIR__ . '/test1.jpg', __DIR__ . '/test2.jpg'],
    attachments: [
        ['name' => 'foo1.jpg', 'file' => __DIR__ . '/test1.jpg'],
        ['name' => 'foo2.jpg', 'file' => __DIR__ . '/test2.jpg']
    ]
);

mailhelper::viewMail(
    mailbox: 'xxx@tld.com',
    folder: 'INBOX/foo/bar',
    id: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxx'
);

mailhelper::moveMail(
    mailbox: 'xxx@tld.com',
    folder: 'INBOX/foo/bar',
    id: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    name: 'INBOX/foo/bar'
);

mailhelper::deleteMail(
    mailbox: 'xxx@tld.com',
    folder: 'INBOX/foo/bar',
    id: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
);

mailhelper::readMail(
    mailbox: 'xxx@tld.com',
    folder: 'INBOX/foo/bar',
    id: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
);

mailhelper::unreadMail(
    mailbox: 'xxx@tld.com',
    folder: 'INBOX/foo/bar',
    id: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
);

mailhelper::getFolders(
    mailbox: 'xxx@tld.com'
);

mailhelper::createFolder(
    mailbox: 'xxx@tld.com'
    name: 'INBOX/foo'
);

mailhelper::renameFolder(
    mailbox: 'xxx@tld.com'
    name_old: 'INBOX/foo',
    name_new: 'INBOX/foo'
);

mailhelper::deleteFolder(
    mailbox: 'xxx@tld.com'
    name: 'INBOX/foo'
);

mailhelper::getConfig();
```

### mcp

```json
{
    "mcpServers": {
        "email": {
            "command": "/usr/bin/php8.1",
            "args": ["/var/www/project/vendor/vielhuber/mailhelper/src/mcp.php"]
        }
    }
}
```
