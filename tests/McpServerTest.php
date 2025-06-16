<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Tool\CallbackToolDefinition;
use Soukicz\Mcp\McpServer;
use Soukicz\Mcp\Model\McpCapabilities;
use Soukicz\Mcp\Model\McpPrompt;
use Soukicz\Mcp\Model\McpPromptContent;
use Soukicz\Mcp\Model\McpPromptMessage;
use Soukicz\Mcp\Model\McpResource;
use Soukicz\Mcp\Model\McpServerInfo;

class McpServerTest extends TestCase
{
    private McpServer $server;

    protected function setUp(): void
    {
        $serverInfo = new McpServerInfo(
            name: 'Test Server',
            version: '1.0.0'
        );

        $capabilities = new McpCapabilities(
            tools: true,
            prompts: true,
            resources: true
        );

        $tool = new CallbackToolDefinition(
            'test_tool',
            'A test tool',
            ['type' => 'object'],
            static fn(array $args) => LLMMessageContents::fromString('result: ' . ($args['input'] ?? 'default'))
        );

        $prompt = new McpPrompt(
            name: 'test_prompt',
            description: 'A test prompt',
            arguments: [],
            messages: [
                new McpPromptMessage(
                    role: 'user',
                    content: new McpPromptContent(type: 'text', text: 'Test message')
                )
            ]
        );

        $resource = new McpResource(
            uri: 'test://resource',
            name: 'Test Resource',
            description: 'A test resource',
            mimeType: 'text/plain',
            content: 'test content',
            text: 'test content'
        );

        $this->server = new McpServer(
            serverInfo: $serverInfo,
            capabilities: $capabilities,
            tools: [$tool],
            prompts: [$prompt],
            resources: [$resource]
        );
    }

    public function testGetServerInfo(): void
    {
        $info = $this->server->getServerInfo();

        $this->assertEquals('Test Server', $info->name);
        $this->assertEquals('1.0.0', $info->version);
    }

    public function testGetCapabilities(): void
    {
        $capabilities = $this->server->getCapabilities();

        $this->assertTrue($capabilities->tools);
        $this->assertTrue($capabilities->prompts);
        $this->assertTrue($capabilities->resources);
        $this->assertFalse($capabilities->logging);
    }

    public function testGetTools(): void
    {
        $tools = $this->server->getTools();

        $this->assertCount(1, $tools);
        $this->assertEquals('test_tool', $tools[0]->getName());
    }

    public function testCallTool(): void
    {
        $result = $this->server->callTool('test_tool', ['input' => 'hello']);

        $this->assertInstanceOf(LLMMessageContents::class, $result);
        $this->assertEquals('result: hello', $result->getMessages()[0]->getText());
    }

    public function testCallToolWithoutArguments(): void
    {
        $result = $this->server->callTool('test_tool', []);

        $this->assertInstanceOf(LLMMessageContents::class, $result);
        $this->assertEquals('result: default', $result->getMessages()[0]->getText());
    }

    public function testCallNonExistentTool(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Tool 'nonexistent' not found");

        $this->server->callTool('nonexistent', []);
    }

    public function testGetPrompts(): void
    {
        $prompts = $this->server->getPrompts();

        $this->assertCount(1, $prompts);
        $this->assertEquals('test_prompt', $prompts[0]->name);
    }

    public function testGetPrompt(): void
    {
        $prompt = $this->server->getPrompt('test_prompt');

        $this->assertEquals('test_prompt', $prompt->name);
        $this->assertEquals('A test prompt', $prompt->description);
    }

    public function testGetNonExistentPrompt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Prompt 'nonexistent' not found");

        $this->server->getPrompt('nonexistent');
    }

    public function testGetResources(): void
    {
        $resources = $this->server->getResources();

        $this->assertCount(1, $resources);
        $this->assertEquals('test://resource', $resources[0]->uri);
    }

    public function testGetResource(): void
    {
        $resource = $this->server->getResource('test://resource');

        $this->assertEquals('test://resource', $resource->uri);
        $this->assertEquals('Test Resource', $resource->name);
        $this->assertEquals('test content', $resource->text);
    }

    public function testGetNonExistentResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Resource 'nonexistent' not found");

        $this->server->getResource('nonexistent');
    }

    public function testCreateSession(): void
    {
        $session = $this->server->createSession('2024-11-05', ['tools' => true]);

        $this->assertNotEmpty($session->id);
        $this->assertEquals('2024-11-05', $session->protocolVersion);
        $this->assertEquals(['tools' => true], $session->clientCapabilities);
        $this->assertFalse($session->initialized);
    }

    public function testInitializeSession(): void
    {
        $session = $this->server->createSession('2024-11-05', []);
        $initializedSession = $this->server->initializeSession($session->id);

        $this->assertTrue($initializedSession->initialized);
        $this->assertEquals($session->id, $initializedSession->id);
    }

    public function testRemoveSession(): void
    {
        $session = $this->server->createSession('2024-11-05', []);

        $this->assertNotNull($this->server->getSession($session->id));

        $this->server->removeSession($session->id);

        $this->assertNull($this->server->getSession($session->id));
    }
}
