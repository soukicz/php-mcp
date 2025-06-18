<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Session;

class FileSessionManager implements SessionManagerInterface
{
    private string $sessionDir;
    private int $sessionTtl;
    private bool $autoInitialize;

    public function __construct(string $sessionDir, int $sessionTtl = 3600, bool $autoInitialize = false)
    {
        $this->sessionDir = $sessionDir;
        $this->sessionTtl = $sessionTtl;
        $this->autoInitialize = $autoInitialize;

        if (!is_dir($this->sessionDir)) {
            throw new \InvalidArgumentException('Session directory does not exist: ' . $this->sessionDir);
        }
    }

    public function createSession(string $sessionId, array $clientInfo): void
    {
        // Check if session already exists (might have been created by markRequestIdUsed)
        $existingData = $this->readSessionFile($sessionId);
        
        $sessionData = [
            'id' => $sessionId,
            'clientInfo' => $clientInfo,
            'initialized' => false,
            'createdAt' => $existingData['createdAt'] ?? time(),
            'lastAccessedAt' => time(),
            'usedRequestIds' => $existingData['usedRequestIds'] ?? []
        ];

        $this->writeSessionFile($sessionId, $sessionData);
    }

    public function getSessionInfo(string $sessionId): ?array
    {
        $sessionData = $this->readSessionFile($sessionId);
        
        if ($sessionData === null) {
            return null;
        }

        // Check if session has expired
        if (time() - $sessionData['lastAccessedAt'] > $this->sessionTtl) {
            $this->terminateSession($sessionId);
            return null;
        }

        // Update last accessed time only if it's been more than 60 seconds since last update
        // This reduces file I/O and race conditions
        $currentTime = time();
        if ($currentTime - $sessionData['lastAccessedAt'] > 60) {
            $sessionData['lastAccessedAt'] = $currentTime;
            try {
                $this->writeSessionFile($sessionId, $sessionData);
            } catch (\RuntimeException $e) {
                // If we can't update the timestamp, continue anyway
                // The session is still valid
            }
        }

        return $sessionData;
    }

    public function isSessionInitialized(string $sessionId): bool
    {
        $sessionData = $this->getSessionInfo($sessionId);
        
        if (!$this->autoInitialize) {
            // Standard behavior
            return $sessionData !== null && $sessionData['initialized'] === true;
        }
        
        // Auto-initialization mode
        if ($sessionData === null) {
            // Session doesn't exist, create and initialize it automatically
            $this->createSession($sessionId, ['name' => 'auto-client', 'version' => '1.0.0']);
            $this->markSessionInitialized($sessionId);
            return true;
        }
        
        if (!$sessionData['initialized']) {
            // Session exists but not initialized, initialize it automatically
            $this->markSessionInitialized($sessionId);
            return true;
        }
        
        return true;
    }

    public function markSessionInitialized(string $sessionId): void
    {
        $sessionData = $this->readSessionFile($sessionId);
        
        if ($sessionData === null) {
            throw new \RuntimeException('Session not found: ' . $sessionId);
        }

        $sessionData['initialized'] = true;
        $sessionData['lastAccessedAt'] = time();
        $this->writeSessionFile($sessionId, $sessionData);
    }

    public function isRequestIdUsed(string $sessionId, string $requestId): bool
    {
        $sessionData = $this->getSessionInfo($sessionId);
        
        if ($sessionData === null) {
            return false;
        }

        return in_array($requestId, $sessionData['usedRequestIds'], true);
    }

    public function markRequestIdUsed(string $sessionId, string $requestId): void
    {
        $sessionData = $this->readSessionFile($sessionId);
        
        if ($sessionData === null) {
            // Session doesn't exist yet - this can happen during initialization
            // Create a minimal session data to track the request ID
            $sessionData = [
                'id' => $sessionId,
                'clientInfo' => [],
                'initialized' => false,
                'createdAt' => time(),
                'lastAccessedAt' => time(),
                'usedRequestIds' => []
            ];
        }

        if (!in_array($requestId, $sessionData['usedRequestIds'], true)) {
            $sessionData['usedRequestIds'][] = $requestId;
            $sessionData['lastAccessedAt'] = time();
            $this->writeSessionFile($sessionId, $sessionData);
        }
    }

    public function terminateSession(string $sessionId): void
    {
        $sessionFile = $this->getSessionFilePath($sessionId);
        
        if (file_exists($sessionFile)) {
            unlink($sessionFile);
        }
    }

    public function cleanupExpiredSessions(): int
    {
        $cleanedCount = 0;
        $currentTime = time();
        
        if (!is_dir($this->sessionDir)) {
            return 0;
        }

        $files = glob($this->sessionDir . '/session_*.json');
        
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            $sessionData = $this->readSessionFileByPath($file);
            
            if ($sessionData === null) {
                // Invalid session file, remove it
                unlink($file);
                $cleanedCount++;
                continue;
            }

            if ($currentTime - $sessionData['lastAccessedAt'] > $this->sessionTtl) {
                unlink($file);
                $cleanedCount++;
            }
        }

        return $cleanedCount;
    }

    public function queueMessage(string $sessionId, array $message): void
    {
        $messagesFile = $this->getMessagesFilePath($sessionId);
        
        // Load existing messages
        $messages = [];
        if (file_exists($messagesFile)) {
            $content = file_get_contents($messagesFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $messages = $decoded;
                }
            }
        }
        
        // Add new message
        $messages[] = $message;
        
        // Save back to file
        file_put_contents($messagesFile, json_encode($messages, JSON_THROW_ON_ERROR));
    }

    public function getPendingMessages(string $sessionId): array
    {
        $messagesFile = $this->getMessagesFilePath($sessionId);
        
        if (!file_exists($messagesFile)) {
            return [];
        }
        
        $content = file_get_contents($messagesFile);
        if ($content === false) {
            return [];
        }
        
        $messages = json_decode($content, true);
        if (!is_array($messages)) {
            return [];
        }
        
        // Clear messages file after reading
        unlink($messagesFile);
        
        return $messages;
    }

    private function getSessionFilePath(string $sessionId): string
    {
        return $this->sessionDir . '/session_' . $sessionId . '.json';
    }

    private function getMessagesFilePath(string $sessionId): string
    {
        return $this->sessionDir . '/messages_' . $sessionId . '.json';
    }

    private function readSessionFile(string $sessionId): ?array
    {
        $sessionFile = $this->getSessionFilePath($sessionId);
        return $this->readSessionFileByPath($sessionFile);
    }

    private function readSessionFileByPath(string $sessionFile): ?array
    {
        if (!file_exists($sessionFile)) {
            return null;
        }

        $content = file_get_contents($sessionFile);
        
        if ($content === false) {
            return null;
        }

        $sessionData = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $sessionData;
    }

    private function writeSessionFile(string $sessionId, array $sessionData): void
    {
        $sessionFile = $this->getSessionFilePath($sessionId);
        $content = json_encode($sessionData, JSON_PRETTY_PRINT);
        
        if (file_put_contents($sessionFile, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write session file: ' . $sessionFile);
        }
    }

    public function getSessionDir(): string
    {
        return $this->sessionDir;
    }

    public function getSessionTtl(): int
    {
        return $this->sessionTtl;
    }

    public function isAutoInitializeEnabled(): bool
    {
        return $this->autoInitialize;
    }

    public function setAutoInitialize(bool $autoInitialize): void
    {
        $this->autoInitialize = $autoInitialize;
    }
}
