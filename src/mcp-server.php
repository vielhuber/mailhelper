<?php
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

use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use PhpMcp\Server\Server;
use PhpMcp\Server\Transports\StdioServerTransport;
try {
    $server = Server::make()
        ->withServerInfo('Mail Helper MCP Server', '1.0.0')
        ->withInstructions(
            'This server provides email handling capabilities using PHP-IMAP. ' .
                'It can read, send, and manage emails through IMAP connections.'
        )
        ->withLogger((new Logger('mcp'))->pushHandler(new StreamHandler(__DIR__ . '/mcp-server.log', Level::Debug)))
        ->withSession('array', 60 * 60 * 8)
        ->build();
    $server->discover(basePath: __DIR__, scanDirs: ['.']);
    $server->listen(new StdioServerTransport());
} catch (\Throwable $e) {
    fwrite(STDERR, '[CRITICAL ERROR] ' . $e->getMessage() . "\n");
    die();
}
