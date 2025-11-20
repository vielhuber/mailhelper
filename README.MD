# 📫 mailhelper 📫

mailhelper is a lightweight whatsapp integration layer built on top of [baileys](https://github.com/WhiskeySockets/Baileys) that provides a simple cli, php wrapper, and mcp server for fetching messages, sending direct and group messages, and wiring whatsapp into existing tooling (wordpress, node, mcp clients) without having to deal with the full session lifecycle yourself.

## requirements

- php >= 8.1

## installation

```sh
composer require vielhuber/mailhelper
```

## usage

### cli

```sh
php mailhelper \
    --disable-warning=ExperimentalWarning \
    --device "xxxxxxxxxxxx" \
    ...

    --action "send_user" \
    --number "xxxxxxxxxxxx" \
    --message "This is a test! 🚀"

    --action "send_group" \
    --name "Group name" \
    --message "This is a test! 🚀"

    --action "send_user" \
    --number "xxxxxxxxxxxx" \
    --message "This is a test! 🚀" \
    --attachments="/full/path/to/file.pdf,/full/path/to/image.png"
```

### php

```php
require_once __DIR__ . '/vendor/autoload.php';
use vielhuber\mailhelper\mailhelper;

// receive emails
mailhelper::fetch(
    mailbox: 'xxx@tld.com',
    folder: 'INBOX/foo/bar',
    filter: [
        'from' => date('Y-m-d H:i:s', strtotime('now - 1 hour')),
        'to' => date('Y-m-d H:i:s', strtotime('now')),
        'subject' => 'pattern',
        'message' => 'pattern'
    ],
    limit: 42
]);

// get folders
mailhelper::getFolders(
    mailbox: 'xxx@tld.com'
]);

// send email
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

```
{
    "mcpServers": {
        "email": {
            "command": "/usr/bin/php8.3",
            "args": ["/var/www/project/vendor/vielhuber/mailhelper/src/mcp.php"]
        }
    }
}
```
