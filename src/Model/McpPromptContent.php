<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Model;

use Soukicz\Mcp\McpSerializableInterface;

class McpPromptContent implements McpSerializableInterface
{
    public function __construct(
        public readonly string $type,
        public readonly string $text,
    )
    {
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'text' => $this->text,
        ];
    }
}
