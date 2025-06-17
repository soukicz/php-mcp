<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Exception;

use Exception;

class McpException extends Exception
{
    public function __construct(string $message, int $code = -32603, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}