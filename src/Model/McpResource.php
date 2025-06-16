<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Model;

use Soukicz\Mcp\McpSerializableInterface;

class McpResource implements McpSerializableInterface
{
    public function __construct(
        public readonly string  $uri,
        public readonly string  $name,
        public readonly string  $description,
        public readonly string  $mimeType,
        public readonly mixed   $content,
        public readonly ?string $text = null,
        public readonly ?string $blob = null,
    )
    {
    }

    public function toArray(): array
    {
        $result = [
            'uri' => $this->uri,
            'name' => $this->name,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
        ];

        if ($this->text !== null) {
            $result['text'] = $this->text;
        }

        if ($this->blob !== null) {
            $result['blob'] = $this->blob;
        }

        return $result;
    }

    public function toListArray(): array
    {
        return [
            'uri' => $this->uri,
            'name' => $this->name,
            'description' => $this->description,
            'mimeType' => $this->mimeType,
        ];
    }
}
