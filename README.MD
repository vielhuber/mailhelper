# 📫 mailhelper 📫

mailhelper is a lightweight email integration layer on top of [phpmailer](https://github.com/PHPMailer/PHPMailer) and [php-imap](https://github.com/Webklex/php-imap) that provides a simple cli, php wrapper, and mcp server for fetching messages, sending emails, and wiring email functionality into existing tooling without having to deal with complex imap/smtp configuration yourself.

## requirements

- php >= 8.1

## installation

### composer

```sh
composer require vielhuber/mailhelper
```

### config

```sh
cp config.example.json config.json
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
            "encryption": "ssl",
            "from_name": "David Vielhuber"
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
            "encryption": "ssl",
            "from_name": "David Vielhuber"
        }
    }
}
```

## usage

### cli

```sh
./vendor/bin/mailhelper fetch \
    --mailbox "xxx@tld.com" \
    --folder "INBOX/foo/bar" \
    --filter-date-from "YYYY-MM-DD HH:II:SS" \
    --filter-date-until "YYYY-MM-DD HH:II:SS" \
    --filter-subject "pattern" \
    --filter-message "pattern" \
    --filter-to "pattern" \
    --filter-cc "pattern" \
    --filter-bcc "pattern" \
    --limit 42 \
    --with-attachments

./vendor/bin/mailhelper folders
    --mailbox "xxx@tld.com"

./vendor/bin/mailhelper send
    --mailbox "xxx@tld.com" \
    --subject: "This is a test! 🚀", \
    --message: "✅ Test <strong>successful</strong>!", \
    --to "aaa@tld.com", \
    --to '["aaa@tld.com", "bbb@tld.com"]', \
    --to '[["name": "John Doe", "email": "aaa@tld.com"], ["name": "Jane Doe", "email": "bbb@tld.com"]]', \
    --cc "aaa@tld.com", \
    --bcc "aaa@tld.com", \
    --attachments: "/test1.jpg", \
    --attachments: '["/test1.jpg", "/test2.jpg"]', \
    --attachments: '[["name": "foo1.jpg", "file": "/test1.jpg"],["name": "foo2.jpg", "file": "/test2.jpg"]]'
```

### php

```php
require_once __DIR__ . '/vendor/autoload.php';
use vielhuber\mailhelper\mailhelper;

mailhelper::fetch(
    mailbox: 'xxx@tld.com',
    folder: 'INBOX/foo/bar',
    filter: [
        'date_from' => date('Y-m-d H:i:s', strtotime('now - 1 hour')),
        'date_until' => date('Y-m-d H:i:s', strtotime('now')),
        'subject' => 'pattern',
        'message' => 'pattern'
        'to' => 'pattern',
        'cc' => 'pattern',
        'bcc' => 'pattern',
    ],
    limit: 42,
    order: 'asc',
    with_attachments: false
]);

mailhelper::folders(
    mailbox: 'xxx@tld.com'
]);

mailhelper::send(
    mailbox: 'xxx@tld.com',
    subject: 'This is a test! 🚀',
    message: '✅ Test <strong>successful</strong>!',
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
]);
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
