<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Model;

use Soukicz\Mcp\McpSerializableInterface;

class McpPrompt implements McpSerializableInterface
{
    /**
     * @param array<string, mixed> $arguments
     * @param McpPromptMessage[] $messages
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array  $arguments,
        public readonly array  $messages,
    )
    {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'arguments' => $this->arguments,
            'messages' => array_map(static fn(McpPromptMessage $message) => $message->toArray(), $this->messages),
        ];
    }
}

