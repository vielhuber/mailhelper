# 📫 mailhelper 📫

mailhelper is a lightweight whatsapp integration layer built on top of [baileys](https://github.com/WhiskeySockets/Baileys) that provides a simple cli, php wrapper, and mcp server for fetching messages, sending direct and group messages, and wiring whatsapp into existing tooling (wordpress, node, mcp clients) without having to deal with the full session lifecycle yourself.

## requirements

- node >= 22
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

// fetch messages
mailhelper::run([
    'device' => 'xxxxxxxxxxxx',
    'action' => 'fetch_messages'
]);

// send messages
mailhelper::run([
    'device' => 'xxxxxxxxxxxx',
    'action' => 'send_user',
    'number' => 'xxxxxxxxxxxx',
    'message' => 'This is a test! 🚀'
]);
mailhelper::run([
    'device' => 'xxxxxxxxxxxx',
    'action' => 'send_group',
    'name' => 'Group name',
    'message' => 'This is a test! 🚀'
]);

// send attachments
mailhelper::run([
    'device' => 'xxxxxxxxxxxx',
    'action' => 'send_user', // or 'send_group'
    /* ... */
    'attachments' => ['/full/path/to/file.pdf', '/full/path/to/image.png']
]);
```

### mcp

```
{
    "mcpServers": {
        "whatsapp": {
            "command": "/root/.nvm/versions/node/v22.20.0/bin/node",
            "args": ["/var/www/project/node_modules/@vielhuber/mailhelper/WhatsApp.js", "--mcp"]
        }
    }
}
```
