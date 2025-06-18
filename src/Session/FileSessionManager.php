<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Session;

use InvalidArgumentException;
use RuntimeException;

class FileSessionManager extends AbstractSessionManager
{
    private string $sessionDir;
    private bool $autoInitialize;

    public function __construct(string $sessionDir, int $sessionTtl = 3600, bool $autoInitialize = false)
    {
        parent::__construct($sessionTtl);

        $this->sessionDir = $sessionDir;
        $this->autoInitialize = $autoInitialize;

        if (!is_dir($this->sessionDir)) {
            throw new InvalidArgumentException('Session directory does not exist: ' . $this->sessionDir);
        }
    }

    public function isSessionInitialized(string $sessionId): bool
    {
        if (!$this->autoInitialize) {
            // Standard behavior from parent
            return parent::isSessionInitialized($sessionId);
        }

        // Auto-initialization mode
        $sessionData = $this->getSessionInfo($sessionId);

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

    protected function updateSessionActivity(string $sessionId, array $sessionData): void
    {
        // Optimize file I/O by only updating if it's been more than 60 seconds
        $currentTime = time();
        if ($currentTime - $sessionData['lastActivity'] > 60) {
            try {
                $this->writeSessionData($sessionId, $sessionData);
            } catch (RuntimeException $e) {
                // If we can't update the timestamp, continue anyway
                // The session is still valid
            }
        }
    }

    protected function readSessionData(string $sessionId): ?array
    {
        $sessionFile = $this->getSessionFilePath($sessionId);
        return $this->readSessionFileByPath($sessionFile);
    }

    protected function writeSessionData(string $sessionId, array $sessionData): void
    {
        $sessionFile = $this->getSessionFilePath($sessionId);
        $content = json_encode($sessionData, JSON_PRETTY_PRINT);

        if (file_put_contents($sessionFile, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write session file: ' . $sessionFile);
        }
    }

    protected function deleteSessionData(string $sessionId): void
    {
        $sessionFile = $this->getSessionFilePath($sessionId);

        if (file_exists($sessionFile)) {
            unlink($sessionFile);
        }
    }

    protected function getAllSessionIds(): array
    {
        if (!is_dir($this->sessionDir)) {
            return [];
        }

        $files = glob($this->sessionDir . '/session_*.json');

        if ($files === false) {
            return [];
        }

        $sessionIds = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (preg_match('/^session_(.+)\.json$/', $filename, $matches)) {
                $sessionIds[] = $matches[1];
            }
        }

        return $sessionIds;
    }

    protected function readMessagesData(string $sessionId): array
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

        return $messages;
    }

    protected function writeMessagesData(string $sessionId, array $messages): void
    {
        $messagesFile = $this->getMessagesFilePath($sessionId);
        file_put_contents($messagesFile, json_encode($messages, JSON_THROW_ON_ERROR));
    }

    protected function deleteMessagesData(string $sessionId): void
    {
        $messagesFile = $this->getMessagesFilePath($sessionId);

        if (file_exists($messagesFile)) {
            unlink($messagesFile);
        }
    }

    private function getSessionFilePath(string $sessionId): string
    {
        return $this->sessionDir . '/session_' . $sessionId . '.json';
    }

    private function getMessagesFilePath(string $sessionId): string
    {
        return $this->sessionDir . '/messages_' . $sessionId . '.json';
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

    // Public getters for configuration
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
