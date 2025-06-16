<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Protocol;

use Soukicz\Mcp\McpSerializableInterface;

class McpJsonRpcError implements McpSerializableInterface
{
    public function __construct(
        public readonly int    $code,
        public readonly string $message,
        public readonly mixed  $data = null,
    )
    {
    }

    public function toArray(): array
    {
        $error = [
            'code' => $this->code,
            'message' => $this->message,
        ];

        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return $error;
    }

    public static function parseError(): self
    {
        return new self(-32700, 'Parse error');
    }

    public static function invalidRequest(): self
    {
        return new self(-32600, 'Invalid Request');
    }

    public static function methodNotFound(): self
    {
        return new self(-32601, 'Method not found');
    }

    public static function invalidParams(): self
    {
        return new self(-32602, 'Invalid params');
    }

    public static function internalError(): self
    {
        return new self(-32603, 'Internal error');
    }
}
