<?php

declare(strict_types=1);

namespace Soukicz\Mcp;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Soukicz\Mcp\Session\SessionManagerInterface;
use Soukicz\Mcp\Session\ArraySessionManager;
use Soukicz\Mcp\Exception\InvalidRequestException;
use Soukicz\Mcp\Exception\MethodNotFoundException;
use Soukicz\Mcp\Exception\InvalidParamsException;

class McpServer
{
    private array $tools = [];
    private array $serverInfo = [
        'name' => 'php-mcp-server',
        'version' => '1.0.0'
    ];
    private SessionManagerInterface $sessionManager;

    public function __construct(array $serverInfo = [], ?SessionManagerInterface $sessionManager = null)
    {
        if (isset($serverInfo['name']) && empty($serverInfo['name'])) {
            throw new InvalidParamsException('Server name cannot be empty');
        }
        
        $this->serverInfo = array_merge($this->serverInfo, $serverInfo);
        $this->sessionManager = $sessionManager ?? new ArraySessionManager();
    }

    public function registerTool(string $name, string $description, array $inputSchema, callable $handler): void
    {
        if (empty($name)) {
            throw new InvalidParamsException('Tool name cannot be empty');
        }
        
        if (empty($description)) {
            throw new InvalidParamsException('Tool description cannot be empty');
        }
        
        $this->tools[$name] = [
            'name' => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler' => $handler
        ];
    }

    public function unregisterTool(string $name): bool
    {
        if (isset($this->tools[$name])) {
            unset($this->tools[$name]);
            return true;
        }
        return false;
    }

    public function hasTool(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function getRegisteredTools(): array
    {
        return array_keys($this->tools);
    }

    public function handleRequest(RequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->createErrorResponse(-32600, 'Invalid Request', 'Only POST method is supported');
        }

        // Handle session management
        $sessionId = $request->getHeaderLine('Mcp-Session-Id');
        if (empty($sessionId)) {
            $sessionId = $this->generateSessionId();
        }

        $contentType = $request->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/json') === false) {
            return $this->createErrorResponse(-32600, 'Invalid Request', 'Content-Type must be application/json');
        }

        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->createErrorResponse(-32700, 'Parse error', 'Invalid JSON');
        }

        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            return $this->createErrorResponse(-32600, 'Invalid Request', 'Missing or invalid jsonrpc version');
        }

        if (!isset($data['method'])) {
            return $this->createErrorResponse(-32600, 'Invalid Request', 'Missing method');
        }

        $id = $data['id'] ?? null;
        $method = $data['method'];
        $params = $data['params'] ?? [];

        // Handle notifications (requests without ID)
        $isNotification = !isset($data['id']);
        
        // Validate request ID for non-notifications
        if (!$isNotification) {
            if ($id === null) {
                return $this->createErrorResponse(-32600, 'Invalid Request', 'Request ID must not be null');
            }
            
            if ($this->sessionManager->isRequestIdUsed($sessionId, (string)$id)) {
                return $this->createErrorResponse(-32600, 'Invalid Request', 'Request ID already used in this session');
            }
            $this->sessionManager->markRequestIdUsed($sessionId, (string)$id);
        }

        // Check if session is initialized for restricted methods
        if (!$this->sessionManager->isSessionInitialized($sessionId) && !in_array($method, ['initialize', 'initialized', 'ping'])) {
            return $this->createErrorResponse(-32600, 'Invalid Request', 'Session not initialized');
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
                'tools' => []
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
            $tools[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema']
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
        $handler = $tool['handler'];

        try {
            $result = $handler($arguments);
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => is_string($result) ? $result : json_encode($result)
                    ]
                ]
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException('Tool execution failed: ' . $e->getMessage(), -32603);
        }
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

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
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

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
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
}