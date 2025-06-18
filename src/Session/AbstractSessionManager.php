<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Session;

use InvalidArgumentException;

abstract class AbstractSessionManager implements SessionManagerInterface
{
    protected int $sessionTtl;

    public function __construct(int $sessionTtl = 3600)
    {
        if ($sessionTtl <= 0) {
            throw new InvalidArgumentException('Session TTL must be positive');
        }

        $this->sessionTtl = $sessionTtl;
    }

    public function createSession(string $sessionId, array $clientInfo): void
    {
        if (empty($sessionId)) {
            throw new InvalidArgumentException('Session ID cannot be empty');
        }

        // Check if session already exists
        $existingData = $this->readSessionData($sessionId);

        $sessionData = [
            'id' => $sessionId,
            'clientInfo' => $clientInfo,
            'initialized' => false,
            'createdAt' => $existingData['createdAt'] ?? time(),
            'lastActivity' => time(),
            'usedRequestIds' => $existingData['usedRequestIds'] ?? []
        ];

        $this->writeSessionData($sessionId, $sessionData);
    }

    public function markSessionInitialized(string $sessionId): void
    {
        $sessionData = $this->readSessionData($sessionId);

        if ($sessionData === null) {
            return;
        }

        $sessionData['initialized'] = true;
        $sessionData['lastActivity'] = time();
        $this->writeSessionData($sessionId, $sessionData);
    }

    public function isSessionInitialized(string $sessionId): bool
    {
        $sessionData = $this->getSessionInfo($sessionId);

        if ($sessionData === null) {
            return false;
        }

        return $sessionData['initialized'] === true;
    }

    public function getSessionInfo(string $sessionId): ?array
    {
        $sessionData = $this->readSessionData($sessionId);

        if ($sessionData === null) {
            return null;
        }

        // Check if session has expired
        if ($this->isSessionExpired($sessionData)) {
            $this->terminateSession($sessionId);
            return null;
        }

        // Update last activity time
        $sessionData['lastActivity'] = time();
        $this->updateSessionActivity($sessionId, $sessionData);

        return $sessionData;
    }

    public function isRequestIdUsed(string $sessionId, string $requestId): bool
    {
        $sessionData = $this->readSessionData($sessionId);

        if ($sessionData === null) {
            return false;
        }

        return in_array($requestId, $sessionData['usedRequestIds'] ?? [], true);
    }

    public function markRequestIdUsed(string $sessionId, string $requestId): void
    {
        $sessionData = $this->readSessionData($sessionId);

        if ($sessionData === null) {
            // Session doesn't exist yet - create minimal session data
            $sessionData = [
                'id' => $sessionId,
                'clientInfo' => [],
                'initialized' => false,
                'createdAt' => time(),
                'lastActivity' => time(),
                'usedRequestIds' => []
            ];
        }

        if (!in_array($requestId, $sessionData['usedRequestIds'] ?? [], true)) {
            $sessionData['usedRequestIds'][] = $requestId;
            $sessionData['lastActivity'] = time();
            $this->writeSessionData($sessionId, $sessionData);
        }
    }

    public function terminateSession(string $sessionId): void
    {
        $this->deleteSessionData($sessionId);
        $this->deleteMessagesData($sessionId);
    }

    public function cleanupExpiredSessions(): int
    {
        $currentTime = time();
        $cleaned = 0;

        foreach ($this->getAllSessionIds() as $sessionId) {
            $sessionData = $this->readSessionData($sessionId);

            if ($sessionData === null || $this->isSessionExpired($sessionData)) {
                $this->terminateSession($sessionId);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    public function queueMessage(string $sessionId, array $message): void
    {
        $messages = $this->readMessagesData($sessionId);
        $messages[] = $message;
        $this->writeMessagesData($sessionId, $messages);
    }

    public function getPendingMessages(string $sessionId): array
    {
        $messages = $this->readMessagesData($sessionId);

        if (!empty($messages)) {
            // Clear messages after reading
            $this->deleteMessagesData($sessionId);
        }

        return $messages;
    }

    protected function isSessionExpired(array $sessionData): bool
    {
        return (time() - $sessionData['lastActivity']) > $this->sessionTtl;
    }

    protected function updateSessionActivity(string $sessionId, array $sessionData): void
    {
        // Default implementation writes the session data
        // Subclasses can override for optimization (e.g., FileSessionManager with throttling)
        $this->writeSessionData($sessionId, $sessionData);
    }

    // Abstract methods that subclasses must implement for storage
    abstract protected function readSessionData(string $sessionId): ?array;

    abstract protected function writeSessionData(string $sessionId, array $sessionData): void;

    abstract protected function deleteSessionData(string $sessionId): void;

    abstract protected function getAllSessionIds(): array;

    abstract protected function readMessagesData(string $sessionId): array;

    abstract protected function writeMessagesData(string $sessionId, array $messages): void;

    abstract protected function deleteMessagesData(string $sessionId): void;
}
