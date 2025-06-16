<?php

declare(strict_types=1);

namespace Soukicz\Mcp;

use GuzzleHttp\Promise\PromiseInterface;
use InvalidArgumentException;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Mcp\Auth\McpAuthContext;
use Soukicz\Mcp\Model\McpCapabilities;
use Soukicz\Mcp\Model\McpPrompt;
use Soukicz\Mcp\Model\McpResource;
use Soukicz\Mcp\Model\McpServerInfo;
use Soukicz\Mcp\Model\McpSession;
use Soukicz\Mcp\Tool\McpToolDefinition;

class McpServer implements McpServerInterface
{
    private array $sessions = [];

    /**
     * @param ToolDefinition[] $tools
     * @param McpPrompt[] $prompts
     * @param McpResource[] $resources
     */
    public function __construct(
        private readonly McpServerInfo   $serverInfo,
        private readonly McpCapabilities $capabilities,
        private readonly array           $tools = [],
        private readonly array           $prompts = [],
        private readonly array           $resources = [],
    )
    {
    }

    public function getServerInfo(): McpServerInfo
    {
        return $this->serverInfo;
    }

    public function getCapabilities(): McpCapabilities
    {
        return $this->capabilities;
    }

    public function getTools(): array
    {
        return $this->tools;
    }

    public function getPrompts(): array
    {
        return $this->prompts;
    }

    public function getResources(): array
    {
        return $this->resources;
    }

    public function callTool(string $name, array $arguments, ?McpAuthContext $authContext = null): LLMMessageContents|PromiseInterface
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                if ($tool instanceof McpToolDefinition) {
                    return $tool->handle($arguments, $authContext);
                }
                return $tool->handle($arguments);
            }
        }

        throw new InvalidArgumentException("Tool '$name' not found");
    }

    public function getPrompt(string $name, array $arguments = []): McpPrompt
    {
        foreach ($this->prompts as $prompt) {
            if ($prompt->name === $name) {
                return $prompt;
            }
        }

        throw new InvalidArgumentException("Prompt '$name' not found");
    }

    public function getResource(string $uri): McpResource
    {
        foreach ($this->resources as $resource) {
            if ($resource->uri === $uri) {
                return $resource;
            }
        }

        throw new InvalidArgumentException("Resource '$uri' not found");
    }

    public function createSession(string $protocolVersion, array $clientCapabilities): McpSession
    {
        $session = McpSession::create($protocolVersion, $clientCapabilities);
        $this->sessions[$session->id] = $session;
        return $session;
    }

    public function getSession(string $sessionId): ?McpSession
    {
        return $this->sessions[$sessionId] ?? null;
    }

    public function initializeSession(string $sessionId): McpSession
    {
        $session = $this->getSession($sessionId);
        if ($session === null) {
            throw new InvalidArgumentException("Session '$sessionId' not found");
        }

        $initializedSession = $session->withInitialized();
        $this->sessions[$sessionId] = $initializedSession;
        return $initializedSession;
    }

    public function removeSession(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }
}
