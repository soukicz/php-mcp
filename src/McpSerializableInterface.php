<?php

declare(strict_types=1);

namespace Soukicz\Mcp;

interface McpSerializableInterface
{
    public function toArray(): array;
}
