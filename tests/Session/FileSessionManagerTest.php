<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Tests\Session;

use PHPUnit\Framework\TestCase;
use Soukicz\Mcp\Session\FileSessionManager;

class FileSessionManagerTest extends TestCase
{
    private FileSessionManager $sessionManager;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mcp-test-sessions-' . uniqid();
        
        // Create the session directory
        if (!mkdir($this->tempDir, 0755, true)) {
            throw new \RuntimeException('Failed to create test session directory: ' . $this->tempDir);
        }
        
        $this->sessionManager = new FileSessionManager($this->tempDir, 300); // 5 minute TTL for tests
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir();
    }

    public function testCreateAndGetSession(): void
    {
        $sessionId = 'test-session-123';
        $clientInfo = ['name' => 'test-client', 'version' => '1.0.0'];

        $this->sessionManager->createSession($sessionId, $clientInfo);
        $sessionInfo = $this->sessionManager->getSessionInfo($sessionId);

        $this->assertNotNull($sessionInfo);
        $this->assertEquals($sessionId, $sessionInfo['id']);
        $this->assertEquals($clientInfo, $sessionInfo['clientInfo']);
        $this->assertFalse($sessionInfo['initialized']);
        $this->assertIsInt($sessionInfo['createdAt']);
        $this->assertIsInt($sessionInfo['lastActivity']);
        $this->assertIsArray($sessionInfo['usedRequestIds']);
        $this->assertEmpty($sessionInfo['usedRequestIds']);
    }

    public function testSessionInitialization(): void
    {
        $sessionId = 'test-session-456';
        $clientInfo = ['name' => 'test-client', 'version' => '1.0.0'];

        $this->sessionManager->createSession($sessionId, $clientInfo);
        
        $this->assertFalse($this->sessionManager->isSessionInitialized($sessionId));
        
        $this->sessionManager->markSessionInitialized($sessionId);
        
        $this->assertTrue($this->sessionManager->isSessionInitialized($sessionId));
    }

    public function testRequestIdTracking(): void
    {
        $sessionId = 'test-session-789';
        $clientInfo = ['name' => 'test-client', 'version' => '1.0.0'];
        $requestId = 'request-123';

        $this->sessionManager->createSession($sessionId, $clientInfo);
        
        $this->assertFalse($this->sessionManager->isRequestIdUsed($sessionId, $requestId));
        
        $this->sessionManager->markRequestIdUsed($sessionId, $requestId);
        
        $this->assertTrue($this->sessionManager->isRequestIdUsed($sessionId, $requestId));
        
        // Test that the same request ID can't be used twice
        $this->sessionManager->markRequestIdUsed($sessionId, $requestId);
        $sessionInfo = $this->sessionManager->getSessionInfo($sessionId);
        $this->assertCount(1, $sessionInfo['usedRequestIds']);
    }

    public function testSessionTermination(): void
    {
        $sessionId = 'test-session-term';
        $clientInfo = ['name' => 'test-client', 'version' => '1.0.0'];

        $this->sessionManager->createSession($sessionId, $clientInfo);
        $this->assertNotNull($this->sessionManager->getSessionInfo($sessionId));
        
        $this->sessionManager->terminateSession($sessionId);
        $this->assertNull($this->sessionManager->getSessionInfo($sessionId));
    }

    public function testNonExistentSession(): void
    {
        $this->assertNull($this->sessionManager->getSessionInfo('non-existent'));
        $this->assertFalse($this->sessionManager->isSessionInitialized('non-existent'));
        $this->assertFalse($this->sessionManager->isRequestIdUsed('non-existent', 'request-1'));
    }

    public function testSessionExpiry(): void
    {
        // Create a session manager with very short TTL
        $shortTtlManager = new FileSessionManager($this->tempDir, 1); // 1 second TTL
        
        $sessionId = 'test-session-expire';
        $clientInfo = ['name' => 'test-client', 'version' => '1.0.0'];

        $shortTtlManager->createSession($sessionId, $clientInfo);
        $this->assertNotNull($shortTtlManager->getSessionInfo($sessionId));
        
        // Wait for expiry
        sleep(2);
        
        $this->assertNull($shortTtlManager->getSessionInfo($sessionId));
    }

    public function testSessionDirectoryValidation(): void
    {
        $nonExistentDir = sys_get_temp_dir() . '/mcp-test-nonexistent-' . uniqid();
        $this->assertFalse(is_dir($nonExistentDir));
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Session directory does not exist: ' . $nonExistentDir);
        
        new FileSessionManager($nonExistentDir);
    }

    public function testGetSessionDir(): void
    {
        $this->assertEquals($this->tempDir, $this->sessionManager->getSessionDir());
    }

    public function testGetSessionTtl(): void
    {
        $this->assertEquals(300, $this->sessionManager->getSessionTtl());
    }

    public function testAutoInitializeDisabledByDefault(): void
    {
        $this->assertFalse($this->sessionManager->isAutoInitializeEnabled());
    }

    public function testAutoInitializeEnabled(): void
    {
        $autoSessionManager = new FileSessionManager($this->tempDir, 300, true);
        $this->assertTrue($autoSessionManager->isAutoInitializeEnabled());
        
        $sessionId = 'auto-test-session';
        
        // With auto-initialize, checking if session is initialized should create and initialize it
        $this->assertTrue($autoSessionManager->isSessionInitialized($sessionId));
        
        // Verify the session was actually created
        $sessionInfo = $autoSessionManager->getSessionInfo($sessionId);
        $this->assertNotNull($sessionInfo);
        $this->assertTrue($sessionInfo['initialized']);
        $this->assertEquals(['name' => 'auto-client', 'version' => '1.0.0'], $sessionInfo['clientInfo']);
    }

    public function testSetAutoInitialize(): void
    {
        $this->assertFalse($this->sessionManager->isAutoInitializeEnabled());
        
        $this->sessionManager->setAutoInitialize(true);
        $this->assertTrue($this->sessionManager->isAutoInitializeEnabled());
        
        $this->sessionManager->setAutoInitialize(false);
        $this->assertFalse($this->sessionManager->isAutoInitializeEnabled());
    }

    public function testAutoInitializeWithExistingUninitializedSession(): void
    {
        $autoSessionManager = new FileSessionManager($this->tempDir, 300, true);
        $sessionId = 'existing-uninit-session';
        
        // Create session but don't initialize it
        $autoSessionManager->createSession($sessionId, ['name' => 'test-client', 'version' => '1.0.0']);
        
        // Verify it's not initialized
        $autoSessionManager->setAutoInitialize(false);
        $this->assertFalse($autoSessionManager->isSessionInitialized($sessionId));
        
        // Enable auto-initialize and check again - should auto-initialize
        $autoSessionManager->setAutoInitialize(true);
        $this->assertTrue($autoSessionManager->isSessionInitialized($sessionId));
        
        // Verify it's now initialized
        $sessionInfo = $autoSessionManager->getSessionInfo($sessionId);
        $this->assertTrue($sessionInfo['initialized']);
    }

    private function cleanupTempDir(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        
        rmdir($this->tempDir);
    }
}
