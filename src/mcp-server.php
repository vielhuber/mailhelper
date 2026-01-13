<?php
require_once __DIR__ . '/../vendor/autoload.php';
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
        ->build();
    $server->discover(basePath: __DIR__, scanDirs: ['.']);
    $server->listen(new StdioServerTransport());
} catch (\Throwable $e) {
    fwrite(STDERR, '[CRITICAL ERROR] ' . $e->getMessage() . "\n");
    die();
}
