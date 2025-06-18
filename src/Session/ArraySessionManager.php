<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Session;

class ArraySessionManager extends AbstractSessionManager
{
    private array $sessions = [];
    private array $pendingMessages = [];

    protected function readSessionData(string $sessionId): ?array
    {
        return $this->sessions[$sessionId] ?? null;
    }

    protected function writeSessionData(string $sessionId, array $sessionData): void
    {
        $this->sessions[$sessionId] = $sessionData;
    }

    protected function deleteSessionData(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    protected function readMessagesData(string $sessionId): array
    {
        return $this->pendingMessages[$sessionId] ?? [];
    }

    protected function writeMessagesData(string $sessionId, array $messages): void
    {
        $this->pendingMessages[$sessionId] = $messages;
    }

    protected function deleteMessagesData(string $sessionId): void
    {
        unset($this->pendingMessages[$sessionId]);
    }

    // Additional methods specific to ArraySessionManager
    public function getSessionCount(): int
    {
        return count($this->sessions);
    }

    public function getAllSessions(): array
    {
        return $this->sessions;
    }
}
