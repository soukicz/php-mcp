<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Session\ArraySessionManager;
use Soukicz\Mcp\Exception\InvalidParamsException;

class McpServerValidationTest extends TestCase
{
    private McpServer $server;

    protected function setUp(): void
    {
        $sessionManager = new ArraySessionManager();
        $this->server = new McpServer(['name' => 'test-server', 'version' => '1.0.0'], $sessionManager);
    }

    public function testEmptyServerNameThrowsException(): void
    {
        $this->expectException(InvalidParamsException::class);
        $this->expectExceptionMessage('Server name cannot be empty');
        
        new McpServer(['name' => ''], new ArraySessionManager());
    }

    public function testValidServerInfo(): void
    {
        $serverInfo = ['name' => 'test-server', 'version' => '2.0.0'];
        $server = new McpServer($serverInfo, new ArraySessionManager());
        
        $sessionId = $this->initializeSession($server);
        $sessionInfo = $server->getSessionInfo($sessionId);
        
        $this->assertNotNull($sessionInfo);
    }

    private function initializeSession(McpServer $server): string
    {
        $request = new \GuzzleHttp\Psr7\ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json']);
        $request = $request->withBody(\GuzzleHttp\Psr7\Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'clientInfo' => ['name' => 'test-client', 'version' => '1.0.0']
            ]
        ])));

        $response = $server->handleRequest($request);
        $sessionId = $response->getHeaderLine('Mcp-Session-Id');
        
        // Send initialized notification
        $request = new \GuzzleHttp\Psr7\ServerRequest('POST', '/mcp', ['Content-Type' => 'application/json', 'Mcp-Session-Id' => $sessionId]);
        $request = $request->withBody(\GuzzleHttp\Psr7\Utils::streamFor(json_encode([
            'jsonrpc' => '2.0',
            'method' => 'initialized'
        ])));
        
        $server->handleRequest($request);
        
        return $sessionId;
    }
}
