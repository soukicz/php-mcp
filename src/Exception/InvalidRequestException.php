<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Exception;

class InvalidRequestException extends McpException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, -32600, $previous);
    }
}