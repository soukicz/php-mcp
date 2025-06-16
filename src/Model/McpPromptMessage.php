<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Model;

use Soukicz\Mcp\McpSerializableInterface;

class McpPromptMessage implements McpSerializableInterface
{
    public function __construct(
        public readonly string           $role,
        public readonly McpPromptContent $content,
    )
    {
    }

    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content->toArray(),
        ];
    }
}
