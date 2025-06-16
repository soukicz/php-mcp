<?php

require_once __DIR__ . '/../vendor/autoload.php';

// Include the League OAuth adapter example
require_once __DIR__ . '/LeagueOAuthServerAdapter.php';

use GuzzleHttp\Psr7\ServerRequest;
use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Model\McpServerInfo;
use Soukicz\Mcp\Model\McpCapabilities;
use Soukicz\Mcp\Transport\McpHttpTransport;
use Soukicz\Mcp\Transport\McpMemoryEventStore;
use Soukicz\Mcp\Auth\McpGenericOAuthMiddleware;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Llm\Message\LLMMessageContents;
use Example\LeagueOAuthServerAdapter;

// Create MCP Server
$serverInfo = new McpServerInfo(
    name: 'Generic OAuth MCP Server',
    version: '1.0.0'
);

$capabilities = new McpCapabilities(tools: true);

$tool = new CallbackToolDefinition(
    'protected_tool',
    'A tool that requires authentication',
    ['type' => 'object'],
    static fn(array $args) => LLMMessageContents::fromString("Authenticated response!")
);

$server = new McpServer($serverInfo, $capabilities, [$tool]);

// Option 1: No OAuth (open access)
$authMiddleware = null;

// Option 2: Use League OAuth2 Server (if available)
if (class_exists('League\OAuth2\Server\AuthorizationServer')) {
    // Your app would configure League OAuth2 with your repositories
    // This is just an example showing the integration pattern
    
    // $authorizationServer = new AuthorizationServer(...);
    // $resourceServer = new ResourceServer(...);
    // 
    // $oauthServer = new LeagueOAuthServerAdapter($authorizationServer, $resourceServer);
    // $authMiddleware = new McpGenericOAuthMiddleware($oauthServer);
}

// Option 3: Custom OAuth implementation
// class MyCustomOAuthServer implements McpOAuthServerInterface {
//     public function validateAccessToken(ServerRequestInterface $request): ?McpAuthContext {
//         // Your custom token validation logic
//     }
//     // ... implement other methods
// }
// $authMiddleware = new McpGenericOAuthMiddleware(new MyCustomOAuthServer());

// Setup transport
$eventStore = new McpMemoryEventStore();
$transport = new McpHttpTransport($server, $eventStore, $authMiddleware);

// Handle request
$request = new ServerRequest(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI'],
    getallheaders() ?: [],
    file_get_contents('php://input')
);

$response = $transport->handleRequest($request);

// Send response
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("{$name}: {$value}");
    }
}
echo $response->getBody()->getContents();