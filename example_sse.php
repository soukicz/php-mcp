<?php

require_once 'vendor/autoload.php';

use GuzzleHttp\Psr7\ServerRequest;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Session\FileSessionManager;

// Create session manager with auto-initialization for easier testing
$sessionManager = new FileSessionManager(sys_get_temp_dir(), 3600, true);

// Create MCP server
$server = new McpServer(['name' => 'example-sse-server', 'version' => '1.0.0'], $sessionManager);

// Register a simple tool
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
    function (array $args) {
        return new \Soukicz\Llm\Message\SuccessResult([
            new \Soukicz\Llm\Message\TextContent($args['message'] ?? 'Hello from SSE server!')
        ]);
    }
));

// Handle different HTTP methods
$request = ServerRequest::fromGlobals();
$response = $server->handleRequest($request);

// Send headers
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", false);
    }
}

// Handle SSE and regular responses
$body = $response->getBody()->getContents();
if ($response->getHeaderLine('Content-Type') === 'text/event-stream') {
    // SSE response - includes endpoint event and any queued messages
    echo $body;
    
    // Example: Queue a notification after tools are called
    // This would be delivered on the client's next GET request
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestData = json_decode(file_get_contents('php://input'), true);
        if (isset($requestData['method']) && $requestData['method'] === 'tools/call') {
            $sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
            if ($sessionId) {
                $server->queueServerMessage($sessionId, 'tools/list_changed');
            }
        }
    }
    
    // Flush the output immediately for SSE
    if (ob_get_level()) {
        ob_end_flush();
    }
    flush();
} else {
    echo $body;
}