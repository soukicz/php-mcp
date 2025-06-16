<?php

declare(strict_types=1);

namespace Soukicz\Mcp;

use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Mcp\Auth\McpAuthContext;
use Soukicz\Mcp\Model\McpCapabilities;
use Soukicz\Mcp\Model\McpPrompt;
use Soukicz\Mcp\Model\McpResource;
use Soukicz\Mcp\Model\McpServerInfo;

interface McpServerInterface
{
    public function getServerInfo(): McpServerInfo;

    public function getCapabilities(): McpCapabilities;

    /**
     * @return ToolDefinition[]
     */
    public function getTools(): array;

    /**
     * @return McpPrompt[]
     */
    public function getPrompts(): array;

    /**
     * @return McpResource[]
     */
    public function getResources(): array;

    public function callTool(string $name, array $arguments, ?McpAuthContext $authContext = null): mixed;

    public function getPrompt(string $name, array $arguments = []): McpPrompt;

    public function getResource(string $uri): McpResource;
}
