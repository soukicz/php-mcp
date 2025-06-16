<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Transport;

interface McpEventStore
{
    public function storeEvent(string $sessionId, McpSseEvent $event): void;

    /**
     * @return McpSseEvent[]
     */
    public function getEvents(string $sessionId, ?string $lastEventId = null): array;

    public function clearEvents(string $sessionId): void;
}
