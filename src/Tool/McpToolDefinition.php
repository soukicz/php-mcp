<?php

namespace Soukicz\Mcp\Tool;

use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Llm\Tool\ToolDefinition;
use Soukicz\Mcp\Auth\McpAuthContext;

interface McpToolDefinition extends ToolDefinition
{
    public function handle(array $input, ?McpAuthContext $authContext = null): PromiseInterface|LLMMessageContents;
}
