<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Session;

interface SessionManagerInterface
{
    public function createSession(string $sessionId, array $clientInfo): void;

    public function markSessionInitialized(string $sessionId): void;

    public function isSessionInitialized(string $sessionId): bool;

    public function getSessionInfo(string $sessionId): ?array;

    public function terminateSession(string $sessionId): void;

    public function isRequestIdUsed(string $sessionId, string $requestId): bool;

    public function markRequestIdUsed(string $sessionId, string $requestId): void;

    public function cleanupExpiredSessions(): int;
}