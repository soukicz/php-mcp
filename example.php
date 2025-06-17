<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Psr7\ServerRequest;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Session\FileSessionManager;

$sessionManager = new FileSessionManager(sys_get_temp_dir(), 3600, true); // Enable auto-initialization
$server = new McpServer([
    'name' => 'example-mcp-server',
    'version' => '1.0.0'
], $sessionManager);

$server->registerTool(new CallbackToolDefinition(
    'echo',
    'Echo back the input message',
    [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string']
        ],
        'required' => ['message']
    ],
    function (array $args): LLMMessageContents {
        return LLMMessageContents::fromString($args['message'] ?? '');
    }
));

$server->registerTool(new CallbackToolDefinition(
    'add',
    'Add two numbers',
    [
        'type' => 'object',
        'properties' => [
            'a' => ['type' => 'number'],
            'b' => ['type' => 'number']
        ],
        'required' => ['a', 'b']
    ],
    function (array $args): LLMMessageContents {
        return LLMMessageContents::fromString(($args['a'] ?? 0) + ($args['b'] ?? 0));
    }
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $request = ServerRequest::fromGlobals();
    $response = $server->handleRequest($request);

    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value", false);
        }
    }

    echo $response->getBody();
} else {
    echo "MCP Server is running. Send POST requests with JSON-RPC 2.0 format.\n";
    echo "Available methods: initialize, tools/list, tools/call\n";
    echo "Available tools: echo, add\n";
    echo "Auto-initialization: " . ($sessionManager->isAutoInitializeEnabled() ? 'ENABLED' : 'DISABLED') . "\n";
}
