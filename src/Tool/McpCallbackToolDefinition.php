<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Tool;

use GuzzleHttp\Promise\PromiseInterface;
use Soukicz\Llm\Message\LLMMessageContents;
use Soukicz\Mcp\Auth\McpAuthContext;

class McpCallbackToolDefinition implements McpToolDefinition
{
    public function __construct(
        private readonly string $name,
        private readonly string $description,
        private readonly array $inputSchema,
        private $handler
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    public function handle(array $input, ?McpAuthContext $authContext = null): PromiseInterface|LLMMessageContents
    {
        return ($this->handler)($input, $authContext);
    }
}
