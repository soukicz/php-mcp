<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Exception;

class MethodNotFoundException extends McpException
{
    public function __construct(string $method, ?\Throwable $previous = null)
    {
        parent::__construct("Method not found: {$method}", -32601, $previous);
    }
}