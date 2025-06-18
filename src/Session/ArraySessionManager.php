<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Session;

class ArraySessionManager implements SessionManagerInterface
{
    private array $sessions = [];
    private array $usedRequestIds = [];
    private array $pendingMessages = [];
    private int $sessionTtl;

    public function __construct(int $sessionTtl = 3600)
    {
        if ($sessionTtl <= 0) {
            throw new \InvalidArgumentException('Session TTL must be positive');
        }
        
        $this->sessionTtl = $sessionTtl;
    }

    public function createSession(string $sessionId, array $clientInfo): void
    {
        if (empty($sessionId)) {
            throw new \InvalidArgumentException('Session ID cannot be empty');
        }
        
        $this->sessions[$sessionId] = [
            'clientInfo' => $clientInfo,
            'initialized' => false,
            'createdAt' => time(),
            'lastActivity' => time()
        ];
    }

    public function markSessionInitialized(string $sessionId): void
    {
        if (!isset($this->sessions[$sessionId])) {
            return;
        }

        $this->sessions[$sessionId]['initialized'] = true;
        $this->sessions[$sessionId]['lastActivity'] = time();
    }

    public function isSessionInitialized(string $sessionId): bool
    {
        if (!isset($this->sessions[$sessionId])) {
            return false;
        }

        $this->sessions[$sessionId]['lastActivity'] = time();
        return $this->sessions[$sessionId]['initialized'];
    }

    public function getSessionInfo(string $sessionId): ?array
    {
        if (!isset($this->sessions[$sessionId])) {
            return null;
        }

        $this->sessions[$sessionId]['lastActivity'] = time();
        return $this->sessions[$sessionId];
    }

    public function terminateSession(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);

        // Clean up used request IDs for this session
        foreach (array_keys($this->usedRequestIds) as $key) {
            if (str_starts_with($key, $sessionId . ':')) {
                unset($this->usedRequestIds[$key]);
            }
        }
    }

    public function isRequestIdUsed(string $sessionId, string $requestId): bool
    {
        $key = $sessionId . ':' . $requestId;
        return isset($this->usedRequestIds[$key]);
    }

    public function markRequestIdUsed(string $sessionId, string $requestId): void
    {
        $key = $sessionId . ':' . $requestId;
        $this->usedRequestIds[$key] = time();
    }

    public function cleanupExpiredSessions(): int
    {
        $now = time();
        $cleaned = 0;

        foreach ($this->sessions as $sessionId => $session) {
            if (($now - $session['lastActivity']) > $this->sessionTtl) {
                $this->terminateSession($sessionId);
                $cleaned++;
            }
        }

        // Also cleanup old request IDs
        foreach ($this->usedRequestIds as $key => $timestamp) {
            if (($now - $timestamp) > $this->sessionTtl) {
                unset($this->usedRequestIds[$key]);
            }
        }

        return $cleaned;
    }

    public function getSessionCount(): int
    {
        return count($this->sessions);
    }

    public function getAllSessions(): array
    {
        return $this->sessions;
    }

    public function queueMessage(string $sessionId, array $message): void
    {
        if (!isset($this->pendingMessages[$sessionId])) {
            $this->pendingMessages[$sessionId] = [];
        }
        
        $this->pendingMessages[$sessionId][] = $message;
    }

    public function getPendingMessages(string $sessionId): array
    {
        if (!isset($this->pendingMessages[$sessionId])) {
            return [];
        }
        
        $messages = $this->pendingMessages[$sessionId];
        $this->pendingMessages[$sessionId] = []; // Clear after retrieving
        
        return $messages;
    }
}