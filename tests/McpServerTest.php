<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Session\ArraySessionManager;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;

class McpServerTest extends TestCase
{
    private McpServer $server;

    protected function setUp(): void
    {
        $sessionManager = new ArraySessionManager();
        $this->server = new McpServer(['name' => 'test-server', 'version' => '1.0.0'], $sessionManager);
        
        $this->server->registerTool(
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
    }

    public function testInitialize(): void
    {
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json']);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0']
            ]
        ])));

        $response = $this->server->handleRequest($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('2.0', $body['jsonrpc']);
        $this->assertEquals(1, $body['id']);
        $this->assertArrayHasKey('result', $body);
        $this->assertEquals('2024-11-05', $body['result']['protocolVersion']);
        $this->assertTrue($response->hasHeader('Mcp-Session-Id'));
    }

    public function testToolsList(): void
    {
        // First initialize session
        $sessionId = $this->initializeSession();
        
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json', 'Mcp-Session-Id' => $sessionId]);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list'
        ])));

        $response = $this->server->handleRequest($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('2.0', $body['jsonrpc']);
        $this->assertArrayHasKey('result', $body);
        $this->assertArrayHasKey('tools', $body['result']);
        $this->assertCount(1, $body['result']['tools']);
        $this->assertEquals('echo', $body['result']['tools'][0]['name']);
    }

    public function testToolsCall(): void
    {
        // First initialize session
        $sessionId = $this->initializeSession();
        
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json', 'Mcp-Session-Id' => $sessionId]);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo',
                'arguments' => ['message' => 'Hello World']
            ]
        ])));

        $response = $this->server->handleRequest($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('2.0', $body['jsonrpc']);
        $this->assertArrayHasKey('result', $body);
        $this->assertArrayHasKey('content', $body['result']);
        $this->assertEquals('Hello World', $body['result']['content'][0]['text']);
    }

    public function testInvalidMethod(): void
    {
        $request = new ServerRequest('GET', '/mcp');
        
        $response = $this->server->handleRequest($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('2.0', $body['jsonrpc']);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals(-32600, $body['error']['code']);
    }

    public function testInvalidJson(): void
    {
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json']);
        $request = $request->withBody(Utils::streamFor('invalid json'));

        $response = $this->server->handleRequest($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('2.0', $body['jsonrpc']);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals(-32700, $body['error']['code']);
    }

    public function testUnknownTool(): void
    {
        // First initialize session
        $sessionId = $this->initializeSession();
        
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json', 'Mcp-Session-Id' => $sessionId]);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'unknown_tool',
                'arguments' => []
            ]
        ])));

        $response = $this->server->handleRequest($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('2.0', $body['jsonrpc']);
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals(-32602, $body['error']['code']);
    }

    public function testSessionLifecycle(): void
    {
        // Test complete session lifecycle
        $sessionId = $this->initializeSession();
        
        // Test that session exists
        $sessionInfo = $this->server->getSessionInfo($sessionId);
        $this->assertNotNull($sessionInfo);
        $this->assertTrue($sessionInfo['initialized']);
        
        // Test session termination
        $this->server->terminateSession($sessionId);
        $this->assertNull($this->server->getSessionInfo($sessionId));
    }

    public function testDuplicateRequestId(): void
    {
        $sessionId = $this->initializeSession();
        
        // Send first request
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json', 'Mcp-Session-Id' => $sessionId]);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'id' => 100,
            'method' => 'tools/list'
        ])));
        
        $response = $this->server->handleRequest($request);
        $this->assertEquals(200, $response->getStatusCode());
        
        // Send duplicate request ID
        $response2 = $this->server->handleRequest($request);
        $body = json_decode((string) $response2->getBody(), true);
        
        $this->assertArrayHasKey('error', $body);
        $this->assertEquals(-32600, $body['error']['code']);
        $this->assertStringContainsString('Request ID already used', $body['error']['data']);
    }

    public function testUninitializedSession(): void
    {
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json']);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list'
        ])));

        $response = $this->server->handleRequest($request);
        $body = json_decode((string) $response->getBody(), true);

        $this->assertArrayHasKey('error', $body);
        $this->assertEquals(-32600, $body['error']['code']);
        $this->assertStringContainsString('Session not initialized', $body['error']['data']);
    }

    public function testNotification(): void
    {
        $sessionId = $this->initializeSession();
        
        // Send notification (no ID)
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json', 'Mcp-Session-Id' => $sessionId]);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'ping'
        ])));

        $response = $this->server->handleRequest($request);
        $this->assertEquals(204, $response->getStatusCode()); // No Content for notifications
    }

    private function initializeSession(): string
    {
        static $initId = 0;
        $initId++;
        
        // Send initialize request
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json']);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'id' => $initId,
            'method' => 'initialize',
            'params' => [
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0']
            ]
        ])));

        $response = $this->server->handleRequest($request);
        $sessionId = $response->getHeaderLine('Mcp-Session-Id');
        
        // Send initialized notification
        $request = new ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json', 'Mcp-Session-Id' => $sessionId]);
        $request = $request->withBody(Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialized'
        ])));
        
        $this->server->handleRequest($request);
        
        return $sessionId;
    }
}