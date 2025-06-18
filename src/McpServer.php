<?php

declare(strict_types=1);

namespace Soukicz\Mcp;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Soukicz\Llm\Client\Anthropic\AnthropicEncoder;
use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Mcp\Session\SessionManagerInterface;
use Soukicz\Mcp\Exception\MethodNotFoundException;
use Soukicz\Mcp\Exception\InvalidParamsException;

class McpServer
{
    /** @var ToolDefinition[] */
    private array $tools = [];
    private array $serverInfo = [
        'name' => 'php-mcp-server',
        'version' => '1.0.0'
    ];
    private SessionManagerInterface $sessionManager;

    public function __construct(array $serverInfo, SessionManagerInterface $sessionManager)
    {
        if (isset($serverInfo['name']) && empty($serverInfo['name'])) {
            throw new InvalidParamsException('Server name cannot be empty');
        }
        
        $this->serverInfo = array_merge($this->serverInfo, $serverInfo);
        $this->sessionManager = $sessionManager;
    }

    public function registerTool(ToolDefinition $toolDefinition): void
    {
        $this->tools[$toolDefinition->getName()] = $toolDefinition;
    }

    public function handleRequest(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        
        // Handle different HTTP methods
        switch ($method) {
            case 'GET':
                return $this->handleGetRequest($request);
            case 'POST':
                return $this->handlePostRequest($request);
            case 'DELETE':
                return $this->handleDeleteRequest($request);
            default:
                return new Response(405, ['Allow' => 'GET, POST, DELETE']);
        }
    }

    private function handleMethod(string $method, array $params, string $sessionId): array
    {
        switch ($method) {
            case 'initialize':
                return $this->handleInitialize($params, $sessionId);
            case 'initialized':
                return $this->handleInitialized($sessionId);
            case 'ping':
                return $this->handlePing();
            case 'tools/list':
                return $this->handleToolsList();
            case 'tools/call':
                return $this->handleToolsCall($params);
            default:
                throw new MethodNotFoundException($method);
        }
    }

    private function handleInitialize(array $params, string $sessionId): array
    {
        $clientInfo = $params['clientInfo'] ?? [];
        
        // Store session info but don't mark as initialized yet
        $this->sessionManager->createSession($sessionId, $clientInfo);
        
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [
                    'listChanged' => false
                ]
            ],
            'serverInfo' => $this->serverInfo
        ];
    }

    private function handleInitialized(string $sessionId): array
    {
        if ($this->sessionManager->getSessionInfo($sessionId) === null) {
            throw new RuntimeException('Session not found', -32600);
        }
        
        $this->sessionManager->markSessionInitialized($sessionId);
        return []; // initialized is a notification, but we return empty array for consistency
    }

    private function handlePing(): array
    {
        return [];
    }

    private function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $schema = $tool->getInputSchema();
            if (empty($schema['properties'])) {
                $schema['properties'] = new \stdClass();
            }
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $schema
            ];
        }

        return ['tools' => $tools];
    }

    private function handleToolsCall(array $params): array
    {
        if (!isset($params['name'])) {
            throw new InvalidParamsException('Missing tool name');
        }

        $toolName = $params['name'];
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$toolName])) {
            throw new InvalidParamsException('Tool not found: ' . $toolName);
        }

        $tool = $this->tools[$toolName];


        $result = $tool->handle($arguments);

        if ($result instanceof PromiseInterface) {
            $resultData = $result->wait();
        } else {
            $resultData = $result;
        }

        $data = [];
        if ($resultData->isError()) {
            $data['isError'] = true;
        }
        $data['content'] = [];
        $encoder = new AnthropicEncoder();
        foreach ($resultData->getMessages() as $message) {
            $data['content'][] = $encoder->encodeMessageContent($message);
        }

        return $data;
    }

    private function createSuccessResponse(array $result, $id = null): ResponseInterface
    {
        $response = [
            'jsonrpc' => '2.0',
            'result' => $result
        ];

        if ($id !== null) {
            $response['id'] = $id;
        }

        return new Response(200, ['Content-Type' => 'application/json'], $this->encodeJson($response));
    }

    private function createErrorResponse(int $code, string $message, ?string $data = null, $id = null): ResponseInterface
    {
        $error = [
            'code' => $code,
            'message' => $message
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        $response = [
            'jsonrpc' => '2.0',
            'error' => $error
        ];

        if ($id !== null) {
            $response['id'] = $id;
        }

        return new Response(200, ['Content-Type' => 'application/json'], $this->encodeJson($response));
    }

    private function encodeJson(array $data): string
    {
        // Custom JSON encoding to handle MCP-specific requirements
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function handleGetRequest(RequestInterface $request): ResponseInterface
    {
        // GET is used for SSE (Server-Sent Events) streaming
        // For PHP-FPM environments, we provide immediate SSE responses without long connections
        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        
        if (empty($sessionId)) {
            $sessionId = $this->generateSessionId();
        }

        // Send required SSE endpoint event immediately and close connection
        $endpointEvent = "event: endpoint\ndata: " . json_encode([
            'uri' => $request->getUri()->getPath() ?: '/mcp',
        ], JSON_THROW_ON_ERROR) . "\n\n";

        // For PHP-FPM compatibility: send immediate response and close
        // No persistent connection - client will reconnect as needed
        $content = $endpointEvent;
        
        // Include any pending messages for this session
        $content .= $this->getPendingSseMessages($sessionId);

        return new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'close', // Close connection immediately
            'X-Accel-Buffering' => 'no',
            'Mcp-Session-Id' => $sessionId
        ], $content);
    }

    private function handlePostRequest(RequestInterface $request): ResponseInterface
    {
        // Handle session management
        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        if (empty($sessionId)) {
            $sessionId = $this->generateSessionId();
        }

        $contentType = $request->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/json') === false) {
            return $this->createErrorResponse(-32600, 'Invalid Request', 'Content-Type must be application/json', null);
        }

        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->createErrorResponse(-32700, 'Parse error', 'Invalid JSON', null);
        }

        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            return $this->createErrorResponse(-32600, 'Invalid Request', 'Missing or invalid jsonrpc version', $data['id'] ?? null);
        }

        if (!isset($data['method'])) {
            return $this->createErrorResponse(-32600, 'Invalid Request', 'Missing method', $data['id'] ?? null);
        }

        $id = $data['id'] ?? null;
        $method = $data['method'];
        $params = $data['params'] ?? [];

        // Handle notifications (requests without ID)
        $isNotification = !isset($data['id']);
        
        // Validate request ID for non-notifications
        if (!$isNotification) {
            if ($id === null) {
                return $this->createErrorResponse(-32600, 'Invalid Request', 'Request ID must not be null', null);
            }
            
            if ($this->sessionManager->isRequestIdUsed($sessionId, (string)$id)) {
                return $this->createErrorResponse(-32600, 'Invalid Request', 'Request ID already used in this session', $id);
            }
            $this->sessionManager->markRequestIdUsed($sessionId, (string)$id);
        }

        // Check if session is initialized for restricted methods
        if (!$this->sessionManager->isSessionInitialized($sessionId) && !in_array($method, ['initialize', 'initialized', 'ping'])) {
            return $this->createErrorResponse(-32600, 'Invalid Request', 'Session not initialized', $id);
        }

        try {
            $result = $this->handleMethod($method, $params, $sessionId);
            
            if ($isNotification) {
                // Notifications don't get responses
                return new Response(204); // No Content
            }
            
            $response = $this->createSuccessResponse($result, $id);
            
            // Add session ID header for initialize responses
            if ($method === 'initialize') {
                $response = $response->withHeader('Mcp-Session-Id', $sessionId);
            }
            
            return $response;
        } catch (InvalidParamsException|MethodNotFoundException $e) {
            if ($isNotification) {
                return new Response(204); // Notifications don't get error responses
            }
            return $this->createErrorResponse($e->getCode(), $e->getMessage(), null, $id);
        } catch (RuntimeException $e) {
            if ($isNotification) {
                return new Response(204);
            }
            return $this->createErrorResponse($e->getCode(), $e->getMessage(), null, $id);
        } catch (\Throwable $e) {
            if ($isNotification) {
                return new Response(204);
            }
            return $this->createErrorResponse(-32603, 'Internal error', $e->getMessage(), $id);
        }
    }

    private function handleDeleteRequest(RequestInterface $request): ResponseInterface
    {
        // DELETE is used for session termination
        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        
        if (empty($sessionId)) {
            return new Response(400, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'Missing Mcp-Session-Id header'
            ]));
        }

        try {
            $this->sessionManager->terminateSession($sessionId);
            return new Response(204); // No Content - successful deletion
        } catch (\Throwable $e) {
            return new Response(500, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'Failed to terminate session: ' . $e->getMessage()
            ]));
        }
    }

    public function getSessionManager(): SessionManagerInterface
    {
        return $this->sessionManager;
    }

    public function getSessionInfo(string $sessionId): ?array
    {
        return $this->sessionManager->getSessionInfo($sessionId);
    }

    public function terminateSession(string $sessionId): void
    {
        $this->sessionManager->terminateSession($sessionId);
    }

    public function cleanupExpiredSessions(): int
    {
        return $this->sessionManager->cleanupExpiredSessions();
    }

    public function queueServerMessage(string $sessionId, string $method, array $params = [], $id = null): void
    {
        $message = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params
        ];
        
        if ($id !== null) {
            $message['id'] = $id;
        }

        $this->sessionManager->queueMessage($sessionId, $message);
    }

    public function queueServerResponse(string $sessionId, array $result, $id): void
    {
        $response = [
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id
        ];

        $this->sessionManager->queueMessage($sessionId, $response);
    }

    public function queueServerError(string $sessionId, int $code, string $message, $data = null, $id = null): void
    {
        $error = [
            'code' => $code,
            'message' => $message
        ];
        
        if ($data !== null) {
            $error['data'] = $data;
        }

        $response = [
            'jsonrpc' => '2.0',
            'error' => $error
        ];
        
        if ($id !== null) {
            $response['id'] = $id;
        }

        $this->sessionManager->queueMessage($sessionId, $response);
    }

    public function formatSseMessage(array $message): string
    {
        return "event: message\ndata: " . json_encode($message) . "\n\n";
    }

    public function getPendingSseMessages(string $sessionId): string
    {
        $messages = $this->sessionManager->getPendingMessages($sessionId);
        $sseContent = '';
        
        foreach ($messages as $message) {
            $sseContent .= $this->formatSseMessage($message);
        }
        
        return $sseContent;
    }
}
