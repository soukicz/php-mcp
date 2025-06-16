<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Transport;

class McpSseEvent
{
    public function __construct(
        public readonly ?string $event = null,
        public readonly ?string $data = null,
        public readonly ?string $id = null,
    )
    {
    }
}
