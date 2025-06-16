<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Transport;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Message\LLMMessageText;
use Soukicz\Mcp\Auth\McpAuthContext;
use Soukicz\Mcp\Auth\McpAuthMiddlewareInterface;
use Soukicz\Mcp\Auth\McpGenericOAuthMiddleware;
use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Protocol\McpJsonRpcError;
use Soukicz\Mcp\Protocol\McpJsonRpcRequest;
use Soukicz\Mcp\Protocol\McpJsonRpcResponse;
use Throwable;

class McpHttpTransport
{
    private array $sseStreams = [];

    public function __construct(
        private readonly McpServer                   $server,
        private readonly ?McpEventStore              $eventStore = null,
        private readonly ?McpAuthMiddlewareInterface $authMiddleware = null,
    )
    {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        // Handle OAuth endpoints first
        if ($this->authMiddleware !== null && str_starts_with($path, '/auth/')) {
            return $this->handleAuthRequest($request);
        }

        // Check authentication for protected endpoints
        if ($this->authMiddleware !== null && $this->authMiddleware->requiresAuthentication($request)) {
            $authContext = $this->authMiddleware->authenticate($request);
            if ($authContext === null) {
                return $this->authMiddleware->handleUnauthorized($request);
            }

            // Add auth context to request attributes
            $request = $request->withAttribute('auth_context', $authContext);
        }

        try {
            return match ($method) {
                'GET' => $this->handleGetRequest($request),
                'POST' => $this->handlePostRequest($request),
                'DELETE' => $this->handleDeleteRequest($request),
                default => new Response(405, ['Content-Type' => 'application/json'],
                    json_encode(['error' => 'Method not allowed'], JSON_THROW_ON_ERROR)),
            };
        } catch (Throwable $e) {
            return new Response(500, ['Content-Type' => 'application/json'],
                json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }
    }

    private function handleGetRequest(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $sessionId = $queryParams['sessionId'] ?? null;
        $lastEventId = $request->getHeaderLine('Last-Event-ID') ?: $queryParams['lastEventId'] ?? null;

        if ($sessionId === null) {
            return new Response(400, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Missing sessionId parameter'], JSON_THROW_ON_ERROR));
        }

        $session = $this->server->getSession($sessionId);
        if ($session === null) {
            return new Response(404, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Session not found'], JSON_THROW_ON_ERROR));
        }

        return $this->createSseStream($sessionId, $lastEventId);
    }

    private function handlePostRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = (string)$request->getBody();
        $queryParams = $request->getQueryParams();
        $sessionId = $queryParams['sessionId'] ?? null;

        if (empty($body)) {
            return new Response(400, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Empty request body'], JSON_THROW_ON_ERROR));
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new Response(400, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Invalid JSON: ' . $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        // Extract session ID from request if available
        if ($sessionId !== null && isset($data['jsonrpc'])) {
            $data['sessionId'] = $sessionId;
        }

        if (isset($data['jsonrpc'])) {
            return $this->handleSingleRequest($data, $request);
        }

        if (is_array($data) && !empty($data) && isset($data[0]['jsonrpc'])) {
            return $this->handleBatchRequest($data, $request);
        }

        return new Response(400, ['Content-Type' => 'application/json'],
            json_encode(['error' => 'Invalid JSON-RPC request'], JSON_THROW_ON_ERROR));
    }

    private function handleDeleteRequest(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $sessionId = $queryParams['sessionId'] ?? null;

        if ($sessionId === null) {
            return new Response(400, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Missing sessionId parameter'], JSON_THROW_ON_ERROR));
        }

        $session = $this->server->getSession($sessionId);
        if ($session === null) {
            return new Response(404, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Session not found'], JSON_THROW_ON_ERROR));
        }

        $this->server->removeSession($sessionId);
        unset($this->sseStreams[$sessionId]);

        return new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['success' => true], JSON_THROW_ON_ERROR));
    }

    private function handleSingleRequest(array $data, ServerRequestInterface $httpRequest): ResponseInterface
    {
        $request = McpJsonRpcRequest::fromArray($data);
        $response = $this->processRequest($request, $httpRequest);

        if ($request->isNotification()) {
            return new Response(204);
        }

        // Check if client wants SSE response by Accept header
        $acceptHeader = $httpRequest->getHeaderLine('Accept');
        if (str_contains($acceptHeader, 'text/event-stream')) {
            return $this->handleSseResponse($response, $data['sessionId'] ?? null);
        }

        return new Response(200, ['Content-Type' => 'application/json'],
            json_encode($response->toArray(), JSON_THROW_ON_ERROR));
    }

    private function handleBatchRequest(array $data, ServerRequestInterface $httpRequest): ResponseInterface
    {
        $responses = [];

        foreach ($data as $item) {
            $request = McpJsonRpcRequest::fromArray($item);
            $response = $this->processRequest($request, $httpRequest);

            if (!$request->isNotification()) {
                $responses[] = $response->toArray();
            }
        }

        if (empty($responses)) {
            return new Response(204);
        }

        return new Response(200, ['Content-Type' => 'application/json'],
            json_encode($responses, JSON_THROW_ON_ERROR));
    }

    private function processRequest(McpJsonRpcRequest $request, ?ServerRequestInterface $httpRequest = null): McpJsonRpcResponse
    {
        try {
            $result = match ($request->method) {
                'initialize' => $this->handleInitialize($request->params),
                'initialized' => $this->handleInitialized($request->params, $httpRequest),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($request->params, $httpRequest),
                'prompts/list' => $this->handlePromptsList(),
                'prompts/get' => $this->handlePromptsGet($request->params),
                'resources/list' => $this->handleResourcesList(),
                'resources/read' => $this->handleResourcesRead($request->params),
                'ping' => ['pong' => true],
                default => throw new InvalidArgumentException("Unknown method: {$request->method}"),
            };

            return McpJsonRpcResponse::success($request->id ?? 0, $result);
        } catch (Throwable $e) {
            return McpJsonRpcResponse::error($request->id ?? 0,
                new McpJsonRpcError(-32603, $e->getMessage()));
        }
    }

    private function handleInitialize(mixed $params): array
    {
        $protocolVersion = $params['protocolVersion'] ?? '2024-11-05';
        $clientCapabilities = $params['capabilities'] ?? [];

        $session = $this->server->createSession($protocolVersion, $clientCapabilities);

        return [
            'protocolVersion' => $protocolVersion,
            'capabilities' => $this->server->getCapabilities()->toArray(),
            'serverInfo' => $this->server->getServerInfo()->toArray(),
            'sessionId' => $session->id,
        ];
    }

    private function handleInitialized(mixed $params, ?ServerRequestInterface $httpRequest = null): array
    {
        $sessionId = null;

        // Try to get session ID from URL params first, then from message params
        if ($httpRequest !== null) {
            $queryParams = $httpRequest->getQueryParams();
            $sessionId = $queryParams['sessionId'] ?? null;
        }

        if ($sessionId === null) {
            $sessionId = $params['sessionId'] ?? null;
        }

        if ($sessionId === null) {
            throw new InvalidArgumentException('Missing sessionId parameter');
        }

        $this->server->initializeSession($sessionId);
        return ['initialized' => true];
    }

    private function handleToolsList(): array
    {
        $tools = [];
        foreach ($this->server->getTools() as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return ['tools' => $tools];
    }

    private function handleToolsCall(mixed $params, ?ServerRequestInterface $httpRequest = null): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if ($name === null) {
            throw new InvalidArgumentException('Missing tool name');
        }

        // Get auth context from request if available
        $authContext = null;
        if ($httpRequest !== null) {
            $authContext = $httpRequest->getAttribute('auth_context');
        }

        $result = $this->server->callTool($name, $arguments, $authContext);
        if ($result instanceof PromiseInterface) {
            $result = $result->wait();
        }
        if (!$result instanceof LLMMessageContents) {
            throw new RuntimeException('Unexpected result type');
        }
        $output = [
            'content' => [],
        ];
        if ($result->isError()) {
            $output['isError'] = true;
        }
        foreach ($result->getMessages() as $content) {
            if ($content instanceof LLMMessageText) {
                $output['content'][] = [
                    'type' => 'text',
                    'text' => $content->getText(),
                ];
            }
        }

        return $output;
    }

    private function handlePromptsList(): array
    {
        $prompts = [];
        foreach ($this->server->getPrompts() as $prompt) {
            $prompts[] = [
                'name' => $prompt->name,
                'description' => $prompt->description,
                'arguments' => $prompt->arguments,
            ];
        }

        return ['prompts' => $prompts];
    }

    private function handlePromptsGet(mixed $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if ($name === null) {
            throw new InvalidArgumentException('Missing prompt name');
        }

        return $this->server->getPrompt($name, $arguments)->toArray();
    }

    private function handleResourcesList(): array
    {
        $resources = [];
        foreach ($this->server->getResources() as $resource) {
            $resources[] = $resource->toListArray();
        }

        return ['resources' => $resources];
    }

    private function handleResourcesRead(mixed $params): array
    {
        $uri = $params['uri'] ?? null;

        if ($uri === null) {
            throw new InvalidArgumentException('Missing resource URI');
        }

        $resource = $this->server->getResource($uri);
        return ['contents' => [$resource->toArray()]];
    }

    private function createSseStream(string $sessionId, ?string $lastEventId = null): ResponseInterface
    {
        $stream = new McpSseStream();
        $this->sseStreams[$sessionId] = $stream;

        if ($this->eventStore !== null) {
            $events = $this->eventStore->getEvents($sessionId, $lastEventId);
            foreach ($events as $event) {
                $stream->writeEvent($event);
            }
        }

        return new Response(200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Cache-Control, Last-Event-ID',
            'Access-Control-Expose-Headers' => 'Last-Event-ID',
        ], $stream);
    }

    private function handleSseResponse(McpJsonRpcResponse $response, ?string $sessionId): ResponseInterface
    {
        if ($sessionId === null) {
            // Return immediate JSON response if no session
            return new Response(200, ['Content-Type' => 'application/json'],
                json_encode($response->toArray(), JSON_THROW_ON_ERROR));
        }

        $this->sendToSession($sessionId, $response->toArray());

        return $this->createSseStream($sessionId);
    }

    public function sendToSession(string $sessionId, array $data): bool
    {
        if (!isset($this->sseStreams[$sessionId])) {
            return false;
        }

        // Generate unique event ID for resumability
        $eventId = $sessionId . '_' . time() . '_' . uniqid('', true);
        $event = new McpSseEvent('message', json_encode($data, JSON_THROW_ON_ERROR), $eventId);

        if ($this->eventStore !== null) {
            $this->eventStore->storeEvent($sessionId, $event);
        }

        $this->sseStreams[$sessionId]->writeEvent($event);
        return true;
    }

    private function handleAuthRequest(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        return match ([$method, $path]) {
            ['GET', '/auth/authorize'] => $this->handleAuthAuthorize($request),
            ['GET', '/auth/callback'] => $this->handleAuthCallback($request),
            ['POST', '/auth/token'] => $this->handleAuthToken($request),
            ['POST', '/auth/revoke'] => $this->handleAuthRevoke($request),
            default => new Response(404, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'Auth endpoint not found'], JSON_THROW_ON_ERROR)),
        };
    }

    private function handleAuthAuthorize(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->authMiddleware === null) {
            return new Response(501, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'OAuth not configured'], JSON_THROW_ON_ERROR));
        }

        if ($this->authMiddleware instanceof McpGenericOAuthMiddleware) {
            return $this->authMiddleware->handleAuthorizationRequest($request);
        }

        return new Response(501, ['Content-Type' => 'application/json'],
            json_encode(['error' => 'OAuth authorization not supported'], JSON_THROW_ON_ERROR));
    }

    private function handleAuthCallback(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->authMiddleware === null) {
            return new Response(501, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'OAuth not configured'], JSON_THROW_ON_ERROR));
        }

        $queryParams = $request->getQueryParams();
        $error = $queryParams['error'] ?? null;

        if ($error !== null) {
            return new Response(400, ['Content-Type' => 'application/json'],
                json_encode([
                    'error' => $error,
                    'error_description' => $queryParams['error_description'] ?? 'OAuth authorization failed',
                ], JSON_THROW_ON_ERROR));
        }

        if ($this->authMiddleware instanceof McpGenericOAuthMiddleware) {
            return $this->authMiddleware->handleAuthorizationRequest($request);
        }

        return new Response(501, ['Content-Type' => 'application/json'],
            json_encode(['error' => 'OAuth callback not supported'], JSON_THROW_ON_ERROR));
    }

    private function handleAuthToken(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->authMiddleware === null) {
            return new Response(501, ['Content-Type' => 'application/json'],
                json_encode(['error' => 'OAuth not configured'], JSON_THROW_ON_ERROR));
        }

        if ($this->authMiddleware instanceof McpGenericOAuthMiddleware) {
            return $this->authMiddleware->handleTokenRequest($request);
        }

        return new Response(501, ['Content-Type' => 'application/json'],
            json_encode(['error' => 'OAuth token endpoint not supported'], JSON_THROW_ON_ERROR));
    }

    private function handleAuthRevoke(ServerRequestInterface $request): ResponseInterface
    {
        // Token revocation endpoint
        return new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['success' => true], JSON_THROW_ON_ERROR));
    }


}
