<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Protocol;

class McpJsonRpcRequest
{
    public function __construct(
        public readonly string          $jsonrpc,
        public readonly string          $method,
        public readonly mixed           $params,
        public readonly string|int|null $id = null,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            jsonrpc: $data['jsonrpc'] ?? '2.0',
            method: $data['method'],
            params: $data['params'] ?? null,
            id: $data['id'] ?? null,
        );
    }

    public function isNotification(): bool
    {
        return $this->id === null;
    }
}
