# PHP MCP Server

A simple PHP implementation of the Model Context Protocol (MCP) server that supports HTTP communication and tool calls using PSR-7 interfaces.

## Features

- ✅ HTTP-only communication (no Server-Sent Events)
- ✅ PSR-7 request/response interfaces for easy integration
- ✅ JSON-RPC 2.0 compliant messaging
- ✅ Simple tool registration and execution
- ✅ Comprehensive error handling
- ✅ Request/response only (no streaming)
- ✅ Tools only (no resources or prompts)

## Installation

```bash
composer require soukicz/mcp
```

## Quick Start

```php
<?php

require_once 'vendor/autoload.php';

use Soukicz\Mcp\McpServer;
use GuzzleHttp\Psr7\ServerRequest;

// Create server instance
$server = new McpServer([
    'name' => 'my-mcp-server',
    'version' => '1.0.0'
]);

// Register a tool
$server->registerTool(
    'echo',
    'Echo back the input message',
    [
        'type' => 'object',
        'properties' => [
            'message' => ['type' => 'string']
        ],
        'required' => ['message']
    ],
    function (array $args): string {
        return $args['message'] ?? '';
    }
);

// Handle PSR-7 request
$request = ServerRequest::fromGlobals();
$response = $server->handleRequest($request);

// Send response
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header("$name: $value", false);
    }
}
echo $response->getBody();
```

## API Reference

### McpServer

#### Constructor

```php
public function __construct(array $serverInfo = [], ?SessionManagerInterface $sessionManager = null)
```

Creates a new MCP server instance with optional server information and session manager. If no session manager is provided, uses `ArraySessionManager` with default settings.

#### registerTool

```php
public function registerTool(string $name, string $description, array $inputSchema, callable $handler): void
```

Registers a new tool with the server.

- `$name` - Tool name
- `$description` - Tool description
- `$inputSchema` - JSON schema for tool input validation
- `$handler` - Callable that executes the tool logic

#### handleRequest

```php
public function handleRequest(RequestInterface $request): ResponseInterface
```

Handles a PSR-7 HTTP request and returns a PSR-7 response.

#### unregisterTool

```php
public function unregisterTool(string $name): bool
```

Removes a tool from the server. Returns `true` if the tool was removed, `false` if it didn't exist.

#### hasTool

```php
public function hasTool(string $name): bool
```

Checks if a tool is registered with the server.

#### getRegisteredTools

```php
public function getRegisteredTools(): array
```

Returns an array of all registered tool names.

## Supported Methods

### initialize

Initializes the MCP connection.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "initialize",
  "params": {
    "clientInfo": {
      "name": "client-name",
      "version": "1.0.0"
    }
  }
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "protocolVersion": "2024-11-05",
    "capabilities": {
      "tools": []
    },
    "serverInfo": {
      "name": "php-mcp-server",
      "version": "1.0.0"
    }
  }
}
```

### tools/list

Lists all available tools.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/list"
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "result": {
    "tools": [
      {
        "name": "echo",
        "description": "Echo back the input message",
        "inputSchema": {
          "type": "object",
          "properties": {
            "message": {"type": "string"}
          },
          "required": ["message"]
        }
      }
    ]
  }
}
```

### tools/call

Executes a tool.

**Request:**
```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "method": "tools/call",
  "params": {
    "name": "echo",
    "arguments": {
      "message": "Hello World"
    }
  }
}
```

**Response:**
```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "result": {
    "content": [
      {
        "type": "text",
        "text": "Hello World"
      }
    ]
  }
}
```

## Error Handling

The server returns JSON-RPC 2.0 compliant error responses:

```json
{
  "jsonrpc": "2.0",
  "id": null,
  "error": {
    "code": -32600,
    "message": "Invalid Request",
    "data": "Only POST method is supported"
  }
}
```

### Error Codes

- `-32700` - Parse error (Invalid JSON)
- `-32600` - Invalid Request (malformed request, session not initialized, etc.)
- `-32601` - Method not found (unsupported MCP method)
- `-32602` - Invalid params (missing required parameters, tool not found)
- `-32603` - Internal error (tool execution failure, server errors)

### Exception Classes

The library provides specific exception classes for better error handling:

```php
use Soukicz\Mcp\Exception\McpException;
use Soukicz\Mcp\Exception\InvalidRequestException;
use Soukicz\Mcp\Exception\MethodNotFoundException;
use Soukicz\Mcp\Exception\InvalidParamsException;

try {
    $server->registerTool('', 'Empty name tool', [], function() {});
} catch (InvalidParamsException $e) {
    // Handle invalid tool registration
}
```

## Integration Examples

### With Slim Framework

```php
use Slim\App;
use Slim\Psr7\Request;
use Slim\Psr7\Response;

$app = new App();

$app->post('/mcp', function (Request $request, Response $response) use ($server) {
    $mcpResponse = $server->handleRequest($request);
    
    $response = $response->withStatus($mcpResponse->getStatusCode());
    foreach ($mcpResponse->getHeaders() as $name => $values) {
        $response = $response->withHeader($name, $values);
    }
    
    $response->getBody()->write((string) $mcpResponse->getBody());
    return $response;
});
```

### With Laravel

```php
use Illuminate\Http\Request;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

Route::post('/mcp', function (Request $request) use ($server) {
    $psr7Factory = new PsrHttpFactory(/* ... */);
    $psrRequest = $psr7Factory->createRequest($request);
    
    $response = $server->handleRequest($psrRequest);
    
    return response((string) $response->getBody())
        ->header('Content-Type', 'application/json');
});
```

### With Symfony and OAuth Authentication

For production applications, you'll typically want to handle authentication at the framework level before the MCP server processes requests.

#### 1. Install Required Dependencies

```bash
composer require symfony/psr-http-message-bridge
composer require guzzlehttp/psr7
```

#### 2. Service Configuration

```yaml
# config/services.yaml
services:
    Soukicz\Mcp\McpServer:
        arguments:
            $serverInfo:
                name: 'symfony-mcp-server'
                version: '1.0.0'
        calls:
            - [registerTool, ['user_info', 'Get current user information', { type: 'object', properties: {} }, '@App\Mcp\Tools\UserInfoTool']]
```

#### 3. Controller with OAuth Integration

```php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Soukicz\Mcp\McpServer;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Bridge\PsrHttpMessage\HttpFoundationFactoryInterface;

class McpController extends AbstractController
{
    public function __construct(
        private McpServer $mcpServer,
        private HttpMessageFactoryInterface $psrFactory,
        private HttpFoundationFactoryInterface $httpFoundationFactory
    ) {}

    #[Route('/api/mcp', methods: ['POST'])]
    #[IsGranted('ROLE_API')] // Ensure user has API access
    public function handleMcp(Request $request): Response
    {
        // OAuth/authentication is handled by Symfony Security component
        // User is available via $this->getUser() if needed
        
        // Convert Symfony Request to PSR-7
        $psrRequest = $this->psrFactory->createRequest($request);
        
        // Handle MCP request
        $psrResponse = $this->mcpServer->handleRequest($psrRequest);
        
        // Convert PSR-7 Response back to Symfony Response
        return $this->httpFoundationFactory->createResponse($psrResponse);
    }
}
```

#### 4. Security Configuration

```yaml
# config/packages/security.yaml
security:
    providers:
        oauth_provider:
            # Configure your OAuth user provider here
    
    firewalls:
        api:
            pattern: ^/api/mcp
            stateless: true
            # Configure OAuth authentication (e.g., using KnpUOAuth2ClientBundle)
            oauth: true
            
    access_control:
        - { path: ^/api/mcp, roles: ROLE_API }
```

#### 5. Example Tool with User Context

```php
<?php

namespace App\Mcp\Tools;

use Symfony\Component\Security\Core\Security;

class UserInfoTool
{
    public function __construct(private Security $security) {}
    
    public function __invoke(array $args): array
    {
        $user = $this->security->getUser();
        
        return [
            'id' => $user?->getId(),
            'username' => $user?->getUserIdentifier(),
            'roles' => $user?->getRoles() ?? []
        ];
    }
}
```

#### Benefits of Framework-Level Authentication

- ✅ Leverage Symfony's robust security component
- ✅ Easy integration with existing OAuth providers
- ✅ Access to authenticated user context in MCP tools  
- ✅ Consistent authentication across your entire API
- ✅ Role-based access control
- ✅ Integration with Symfony's security events and listeners
- ✅ Separation of concerns (HTTP auth vs MCP protocol)

## Session Management

The MCP server provides flexible session management through a pluggable interface system.

### Default Array Session Manager

By default, the server uses `ArraySessionManager` which stores sessions in memory:

```php
use Soukicz\Mcp\McpServer;

// Uses ArraySessionManager with default 1-hour TTL
$server = new McpServer();

// Custom TTL (30 minutes)
$server = new McpServer([], new ArraySessionManager(1800));
```

### Custom Session Manager Interface

Implement `SessionManagerInterface` for custom session storage:

```php
interface SessionManagerInterface
{
    public function createSession(string $sessionId, array $clientInfo): void;
    public function markSessionInitialized(string $sessionId): void;
    public function isSessionInitialized(string $sessionId): bool;
    public function getSessionInfo(string $sessionId): ?array;
    public function terminateSession(string $sessionId): void;
    public function isRequestIdUsed(string $sessionId, string $requestId): bool;
    public function markRequestIdUsed(string $sessionId, string $requestId): void;
    public function cleanupExpiredSessions(): int;
}
```

### Session Cleanup

For long-running applications, regularly cleanup expired sessions:

```php
// In a scheduled command or cron job
$server = new McpServer();
$cleanedCount = $server->cleanupExpiredSessions();
echo "Cleaned up {$cleanedCount} expired sessions\n";
```

### Session Management Benefits

- **Scalability**: Use Redis, database, or other persistent storage
- **Security**: Implement custom session validation and security policies  
- **Monitoring**: Track session metrics and usage patterns
- **Integration**: Leverage existing authentication and session systems
- **Performance**: Optimize session storage for your specific needs

## Security Considerations

### Input Validation

The library includes built-in validation for:
- Server configuration (non-empty names)
- Tool registration (non-empty names and descriptions)
- Session management (valid session IDs)
- JSON-RPC message format

### Session Security

- Session IDs are generated using cryptographically secure random bytes
- Request ID tracking prevents replay attacks within sessions
- Session TTL prevents indefinite session persistence
- Input validation on all session operations

### Recommended Security Practices

```php
// 1. Use HTTPS in production
// 2. Implement proper authentication (OAuth, JWT, etc.) at framework level
// 3. Validate tool input parameters
$server->registerTool('file_read', 'Read file contents', [
    'type' => 'object',
    'properties' => [
        'path' => ['type' => 'string', 'pattern' => '^[a-zA-Z0-9/_.-]+$']
    ],
    'required' => ['path']
], function (array $args): string {
    $path = $args['path'];
    
    // Validate path is within allowed directory
    $realPath = realpath($path);
    if (!$realPath || !str_starts_with($realPath, '/allowed/directory/')) {
        throw new \InvalidArgumentException('Access denied');
    }
    
    return file_get_contents($realPath);
});

// 4. Set appropriate session TTL
$sessionManager = new ArraySessionManager(900); // 15 minutes

// 5. Regular cleanup of expired sessions
$server->cleanupExpiredSessions();
```

## Development

### Running Tests

```bash
docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./vendor/bin/phpunit tests/
```

### Static Analysis

```bash
docker run --rm -i -v $PWD:/usr/src/app thecodingmachine/php:8.1-v4-cli ./vendor/bin/phpstan analyse
```

## Requirements

- PHP 8.1+
- PSR-7 HTTP message implementation (guzzlehttp/psr7)
- JSON extension

## License

BSD-3-Clause

## Contributing

Contributions are welcome! Please ensure tests pass and follow PSR-12 coding standards.
