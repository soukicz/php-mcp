<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Transport;

class McpMemoryEventStore implements McpEventStore
{
    private array $events = [];

    public function storeEvent(string $sessionId, McpSseEvent $event): void
    {
        $this->events[$sessionId][] = $event;
    }

    public function getEvents(string $sessionId, ?string $lastEventId = null): array
    {
        $events = $this->events[$sessionId] ?? [];

        if ($lastEventId === null) {
            return $events;
        }

        // Find events after the last event ID for resumability
        $foundLastEvent = false;
        $resumeEvents = [];

        foreach ($events as $event) {
            if ($foundLastEvent) {
                $resumeEvents[] = $event;
            } elseif ($event->id === $lastEventId) {
                $foundLastEvent = true;
            }
        }

        return $resumeEvents;
    }

    public function clearEvents(string $sessionId): void
    {
        unset($this->events[$sessionId]);
    }
}
