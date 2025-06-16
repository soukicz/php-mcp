## MCP (Model Context Protocol) Server

This library provides a complete PHP implementation of the Model Context Protocol server with HTTP transport. MCP enables seamless integration between LLM applications and external data sources/tools through a standardized JSON-RPC 2.0 protocol.

## Quick Start

```bash
composer require soukicz/mcp
```

Create a simple MCP server:

```php
<?php
require 'vendor/autoload.php';

use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Model\{McpServerInfo, McpCapabilities};
use Soukicz\Mcp\Transport\McpHttpTransport;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Llm\Message\{LLMMessageContents, LLMMessageText};

$server = new McpServer(
    new McpServerInfo('My Server', '1.0.0'),
    new McpCapabilities(tools: true),
    [new CallbackToolDefinition('hello', 'Say hello', [], 
        fn($args) => new LLMMessageContents([new LLMMessageText('Hello!')]))]
);

$transport = new McpHttpTransport($server);
$response = $transport->handleRequest(/* PSR-7 request */);
```

## Features

- **MCP Streamable HTTP Transport**: Full compliance with MCP streamable HTTP specification
- **JSON-RPC 2.0 Protocol**: Complete JSON-RPC 2.0 implementation over HTTP
- **Server-Sent Events (SSE)**: Bidirectional communication with SSE for server-to-client messages
- **Session Management**: Stateful sessions with unique IDs and resumability
- **Event Resumability**: Last-Event-ID support for connection recovery
- **Tool Integration**: Compatible with existing `ToolDefinition` interface
- **User Context in Tools**: Tools can access authenticated user information  
- **Resource & Prompt Support**: Full MCP capabilities for resources and prompts
- **Pluggable Authentication**: OAuth 2.1, custom auth, or no auth - your choice

### Basic MCP Server Setup

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use GuzzleHttp\Psr7\ServerRequest;
use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Model\McpServerInfo;
use Soukicz\Mcp\Model\McpCapabilities;
use Soukicz\Mcp\Transport\McpHttpTransport;
use Soukicz\Mcp\Transport\McpMemoryEventStore;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Llm\Message\LLMMessageContents;

// Create server info
$serverInfo = new McpServerInfo(
    name: 'My MCP Server',
    version: '1.0.0',
    description: 'A PHP MCP server example'
);

// Define capabilities
$capabilities = new McpCapabilities(
    tools: true,
    prompts: true,
    resources: true
);

// Create tools
$helloTool = new CallbackToolDefinition(
    'hello',
    'Say hello to someone',
    [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string', 'description' => 'Name to greet']
        ]
    ],
    function(array $args) {
        return new LLMMessageContents([new \Soukicz\Llm\Message\LLMMessageText("Hello, " . ($args['name'] ?? 'World') . "!")]);
    }
);

// Create server
$server = new McpServer(
    serverInfo: $serverInfo,
    capabilities: $capabilities,
    tools: [$helloTool]
);

// Setup transport with event store for resumability
$eventStore = new McpMemoryEventStore();
$transport = new McpHttpTransport($server, $eventStore);

// Handle HTTP request
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
```

### Tools with User Context

Tools can now access authenticated user information:

```php
use Soukicz\Mcp\Auth\McpAuthContext;

$userAwareTool = new CallbackToolDefinition(
    'get_user_info',
    'Get current user information',
    [
        'type' => 'object',
        'properties' => [],
        'required' => []
    ],
    function(array $args) {
        // Access auth context passed by the server
        $authContext = $args['_auth'] ?? null;
        
        if ($authContext instanceof McpAuthContext && $authContext->user) {
            $user = $authContext->user;
            $info = [
                'id' => $user->id,
                'email' => $user->email,
                'scopes' => $user->scopes
            ];
            return new LLMMessageContents([new \Soukicz\Llm\Message\LLMMessageText(
                'User: ' . json_encode($info, JSON_PRETTY_PRINT)
            )]);
        }
        
        return new LLMMessageContents([new \Soukicz\Llm\Message\LLMMessageText(
            'No user authentication available'
        )]);
    }
);
```

### MCP Server with Resources and Prompts

```php
use Soukicz\Mcp\Model\McpPrompt;
use Soukicz\Mcp\Model\McpPromptMessage;
use Soukicz\Mcp\Model\McpPromptContent;
use Soukicz\Mcp\Model\McpResource;

// Create a prompt
$greetingPrompt = new McpPrompt(
    name: 'greeting',
    description: 'A friendly greeting prompt',
    arguments: [
        'name' => ['type' => 'string', 'description' => 'Name to greet']
    ],
    messages: [
        new McpPromptMessage(
            role: 'system',
            content: new McpPromptContent(type: 'text', text: 'You are a friendly assistant.')
        ),
        new McpPromptMessage(
            role: 'user',
            content: new McpPromptContent(type: 'text', text: 'Say hello to {{name}}!')
        )
    ]
);

// Create a resource
$exampleResource = new McpResource(
    uri: 'file://data.txt',
    name: 'Example Data',
    description: 'Sample data file',
    mimeType: 'text/plain',
    content: 'This is example content from an MCP resource.',
    text: 'This is example content from an MCP resource.'
);

// Add to server
$server = new McpServer(
    serverInfo: $serverInfo,
    capabilities: $capabilities,
    tools: [$helloTool],
    prompts: [$greetingPrompt],
    resources: [$exampleResource]
);
```

### MCP Protocol Methods

The server implements the following standard MCP methods:

#### ✅ Implemented Methods:
- `initialize` - Initialize connection with protocol version and capabilities
- `initialized` - Confirm initialization completed
- `tools/list` - List available tools
- `tools/call` - Execute a tool
- `prompts/list` - List available prompts
- `prompts/get` - Get a specific prompt
- `resources/list` - List available resources
- `resources/read` - Read a specific resource
- `ping` - Health check

#### ⚠️ Missing Standard Methods:
- `logging/setLevel` - Set logging level (capability advertised but not implemented)
- `notifications/progress` - Progress tracking for long-running operations
- `sampling/request` - Server-side LLM sampling
- `roots/list` - Root context management
- `cancellation/cancel` - Operation cancellation
- `resources/subscribe` - Subscribe to resource changes
- `resources/unsubscribe` - Unsubscribe from resource changes
- `tools/subscribe` - Subscribe to tool changes
- `tools/unsubscribe` - Unsubscribe from tool changes
- `prompts/subscribe` - Subscribe to prompt changes
- `prompts/unsubscribe` - Unsubscribe from prompt changes

For complete MCP specification, see [Model Context Protocol Documentation](https://modelcontextprotocol.io/introduction).

### Session Management

MCP sessions are stateful connections between client and server:

```php
// Sessions are automatically created during 'initialize' method
// The server returns a session ID that clients must use for subsequent requests

// Manual session management (usually not needed):
$session = $server->createSession('2024-11-05', ['tools' => true]);
echo $session->id; // Unique session ID like 'mcp_abc123def456'

// Session lifecycle:
// 1. Client calls 'initialize' → Server creates session and returns ID
// 2. Client uses session ID in URL: POST /mcp?sessionId=abc123
// 3. Client can open SSE stream: GET /mcp?sessionId=abc123
// 4. Client calls 'initialized' to complete handshake
// 5. Normal MCP communication proceeds

// Remove session when done
$server->removeSession($session->id);
```

### Event Storage for Resumability

```php
use Soukicz\Mcp\Transport\McpMemoryEventStore;

// Use event store for message replay
$eventStore = new McpMemoryEventStore();
$transport = new McpHttpTransport($server, $eventStore);

// Events are automatically stored and can be replayed for session resumption
```

### Running the Server

1. **PHP Built-in Server** (for development):
```bash
php -S localhost:8080 examples/mcp-server-generic-oauth-example.php
```

2. **Docker** (recommended):
```bash
docker run --rm -v $PWD:/usr/src/app -p 8080:8080 thecodingmachine/php:8.3-v4-cli php -S 0.0.0.0:8080 examples/mcp-server-generic-oauth-example.php
```

3. **Production**: Deploy behind nginx/Apache with proper PHP-FPM configuration

### Testing the MCP Server

#### JSON-RPC over HTTP (POST)

Test your server endpoints using HTTP POST for client-to-server communication:

```bash
# Initialize session
curl -X POST http://localhost:8080 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{"tools":true}}}'

# List tools (using session ID from initialize response)
curl -X POST "http://localhost:8080?sessionId=SESSION_ID" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'

# Call a tool
curl -X POST "http://localhost:8080?sessionId=SESSION_ID" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"hello","arguments":{"name":"World"}}}'
```

#### Server-Sent Events (GET)

For server-to-client communication and bidirectional streaming:

```bash
# Open SSE stream for server-to-client messages
curl -N -H "Accept: text/event-stream" \
  "http://localhost:8080?sessionId=SESSION_ID"

# Resume from last event (connection recovery)
curl -N -H "Accept: text/event-stream" \
     -H "Last-Event-ID: last_received_event_id" \
  "http://localhost:8080?sessionId=SESSION_ID"
```

#### Combined Usage (Real MCP Client)

A proper MCP client would:
1. POST to initialize and get session ID
2. Open SSE connection with GET for server messages
3. Continue using POST for client requests
4. Use Last-Event-ID for connection recovery

The MCP server is fully compatible with MCP clients and can be integrated into any LLM application supporting the Model Context Protocol.

### Authentication

The MCP server supports pluggable authentication via the `McpOAuthServerInterface`. Use any OAuth implementation or create your own.

#### No Authentication (Open Access)

```php
// Simply don't pass any authentication middleware
$transport = new McpHttpTransport($server, $eventStore);
```

#### Generic OAuth Interface

Implement `McpOAuthServerInterface` for any OAuth provider:

```php
use Soukicz\Mcp\Auth\McpOAuthServerInterface;
use Soukicz\Mcp\Auth\McpGenericOAuthMiddleware;

class MyOAuthServer implements McpOAuthServerInterface
{
    public function validateAccessToken(ServerRequestInterface $request): ?McpAuthContext
    {
        // Your token validation logic (JWT, database lookup, API call, etc.)
        $authHeader = $request->getHeaderLine('Authorization');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            // Validate token and return McpAuthContext or null
        }
        return null;
    }

    public function handleAuthorizationRequest(ServerRequestInterface $request): ResponseInterface
    {
        // Handle OAuth authorization flow
    }

    public function handleTokenRequest(ServerRequestInterface $request): ResponseInterface  
    {
        // Handle token exchange/refresh
    }

    public function isPublicEndpoint(ServerRequestInterface $request): bool
    {
        // Define which endpoints don't require authentication
        return str_starts_with($request->getUri()->getPath(), '/public/');
    }
}

// Use your OAuth implementation
$authMiddleware = new McpGenericOAuthMiddleware(new MyOAuthServer());
$transport = new McpHttpTransport($server, $eventStore, $authMiddleware);
```

#### League OAuth2 Server Example

For League OAuth2 Server integration, see `examples/LeagueOAuthServerAdapter.php`:

```php
composer require league/oauth2-server

// Configure your League OAuth2 server with your repositories
$authorizationServer = new AuthorizationServer($clientRepo, $tokenRepo, $scopeRepo, $privateKey, $encryptionKey);
$resourceServer = new ResourceServer($tokenRepo, $publicKey);

// Use the adapter from examples/
$oauthServer = new Example\LeagueOAuthServerAdapter($authorizationServer, $resourceServer);
$authMiddleware = new McpGenericOAuthMiddleware($oauthServer);
```

See `examples/tools-with-user-context.php` for a complete example of user-aware tools.

#### Authentication Features

- **Provider Independent**: Use any OAuth server (League, custom, Firebase Auth, Auth0, etc.)
- **Simple Interface**: Four methods to implement for full OAuth support
- **Flexible Validation**: JWT, database lookup, API calls, or any validation method
- **Your Storage**: Complete control over token storage and client management
- **Custom Logic**: Define your own public endpoints and authorization flows

#### Common OAuth Providers

**League OAuth2 Server**: See `examples/LeagueOAuthServerAdapter.php`
**Custom JWT**: Validate JWT tokens with your secret/public key
**External API**: Validate tokens by calling external auth service
**Database**: Look up tokens in your database
**Firebase Auth**: Validate Firebase ID tokens
**Auth0**: Validate Auth0 JWT tokens

#### Interface Methods

```php
interface McpOAuthServerInterface
{
    // Validate access token from Authorization header
    public function validateAccessToken(ServerRequestInterface $request): ?McpAuthContext;
    
    // Handle GET /auth/authorize requests
    public function handleAuthorizationRequest(ServerRequestInterface $request): ResponseInterface;
    
    // Handle POST /auth/token requests  
    public function handleTokenRequest(ServerRequestInterface $request): ResponseInterface;
    
    // Define which endpoints are public (no auth required)
    public function isPublicEndpoint(ServerRequestInterface $request): bool;
}
```

#### Custom Authentication

Implement `McpAuthMiddlewareInterface` for custom authentication:

```php
class CustomAuthMiddleware implements McpAuthMiddlewareInterface
{
    public function authenticate(ServerRequestInterface $request): ?McpAuthContext
    {
        // Custom authentication logic
        $apiKey = $request->getHeaderLine('X-API-Key');

        if ($this->validateApiKey($apiKey)) {
            $user = new McpUser('api-user', ['read', 'write']);
            $token = new McpOAuthToken($apiKey, 'Bearer');
            return new McpAuthContext($token, $user);
        }

        return null;
    }

    public function requiresAuthentication(ServerRequestInterface $request): bool
    {
        // Define which endpoints require authentication
        return !str_starts_with($request->getUri()->getPath(), '/public/');
    }

    public function handleUnauthorized(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(401, ['Content-Type' => 'application/json'],
            json_encode(['error' => 'API key required']));
    }
}
```

#### Tools with User Context

When authentication is enabled, tools automatically receive user context:

```php
use Soukicz\\Mcp\\Tool\\McpCallbackToolDefinition;
use Soukicz\\Mcp\\Auth\\McpAuthContext;

// Tools can check user permissions
$permissionTool = new McpCallbackToolDefinition(
    'check_permission',
    'Check if user has permission',
    [
        'type' => 'object',
        'properties' => [
            'permission' => ['type' => 'string']
        ],
        'required' => ['permission']
    ],
    function(array $args, ?McpAuthContext $authContext = null) {
        if (!$authContext?->user) {
            return new LLMMessageContents([new \\Soukicz\\Llm\\Message\\LLMMessageText(
                'Authentication required'
            )]);
        }
        
        $hasPermission = $authContext->user->hasScope($args['permission'] ?? '');
        $message = $hasPermission 
            ? \"User has permission: {$args['permission']}\"
            : \"User lacks permission: {$args['permission']}\";
            
        return new LLMMessageContents([new \\Soukicz\\Llm\\Message\\LLMMessageText($message)]);
    }
);
```

**Key Points:**
- Use `McpCallbackToolDefinition` for tools that need user context
- Auth context is passed as second parameter: `function(array $args, ?McpAuthContext $authContext = null)`
- Standard `CallbackToolDefinition` tools work without modification for backward compatibility

#### OAuth Flow Example

```bash
# 1. Get token (depends on your grant type configuration)
curl -X POST http://localhost:8080/auth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=client_credentials&client_id=your-client&client_secret=your-secret"

# 2. Use token for authenticated requests
curl -X POST http://localhost:8080 \
  -H "Authorization: Bearer ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"protected_tool","arguments":{}}}'
```

#### Security Best Practices

- **Always use HTTPS in production**
- **Use proper RSA key management** - Store private keys securely
- **Short-lived tokens** - 15-60 minutes for access tokens, 7-30 days for refresh tokens  
- **Scope validation** - Validate scopes for each protected operation
- **PKCE enforcement** - Automatically enabled for public clients
- **Rate limiting** - Implement rate limiting and abuse detection
- **Audit logging** - Log authentication events for security monitoring
- **Key rotation** - Regularly rotate encryption keys and JWT signing keys
