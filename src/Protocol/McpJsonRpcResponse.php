<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Protocol;

use Soukicz\Mcp\McpSerializableInterface;

class McpJsonRpcResponse implements McpSerializableInterface
{
    public function __construct(
        public readonly string           $jsonrpc,
        public readonly string|int       $id,
        public readonly mixed            $result = null,
        public readonly ?McpJsonRpcError $error = null,
    )
    {
    }

    public function toArray(): array
    {
        $response = [
            'jsonrpc' => $this->jsonrpc,
            'id' => $this->id,
        ];

        if ($this->error !== null) {
            $response['error'] = $this->error->toArray();
        } else {
            $response['result'] = $this->result;
        }

        return $response;
    }

    public static function success(string|int $id, mixed $result): self
    {
        return new self('2.0', $id, $result);
    }

    public static function error(string|int $id, McpJsonRpcError $error): self
    {
        return new self('2.0', $id, null, $error);
    }
}
