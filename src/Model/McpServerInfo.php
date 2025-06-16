<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Model;

use Soukicz\Mcp\McpSerializableInterface;

class McpServerInfo implements McpSerializableInterface
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $version,
        public readonly ?string $description = null,
        public readonly ?string $author = null,
        public readonly ?string $license = null,
    )
    {
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'version' => $this->version,
            'description' => $this->description,
            'author' => $this->author,
            'license' => $this->license,
        ], static fn($value) => $value !== null);
    }
}
