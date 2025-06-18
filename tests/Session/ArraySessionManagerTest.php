<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Tests\Session;

use PHPUnit\Framework\TestCase;
use Soukicz\Mcp\Session\ArraySessionManager;

class ArraySessionManagerTest extends TestCase
{
    private ArraySessionManager $sessionManager;

    protected function setUp(): void
    {
        $this->sessionManager = new ArraySessionManager();
    }

    public function testCreateSession(): void
    {
        $sessionId = 'test-session-1';
        $clientInfo = ['name' => 'test-client', 'version' => '1.0.0'];

        $this->sessionManager->createSession($sessionId, $clientInfo);

        $sessionInfo = $this->sessionManager->getSessionInfo($sessionId);
        $this->assertNotNull($sessionInfo);
        $this->assertEquals($clientInfo, $sessionInfo['clientInfo']);
        $this->assertFalse($sessionInfo['initialized']);
        $this->assertArrayHasKey('createdAt', $sessionInfo);
        $this->assertArrayHasKey('lastActivity', $sessionInfo);
    }

    public function testMarkSessionInitialized(): void
    {
        $sessionId = 'test-session-2';
        $this->sessionManager->createSession($sessionId, []);

        $this->assertFalse($this->sessionManager->isSessionInitialized($sessionId));

        $this->sessionManager->markSessionInitialized($sessionId);

        $this->assertTrue($this->sessionManager->isSessionInitialized($sessionId));
    }

    public function testRequestIdTracking(): void
    {
        $sessionId = 'test-session-3';
        $requestId = 'req-123';

        $this->sessionManager->createSession($sessionId, []);

        $this->assertFalse($this->sessionManager->isRequestIdUsed($sessionId, $requestId));

        $this->sessionManager->markRequestIdUsed($sessionId, $requestId);

        $this->assertTrue($this->sessionManager->isRequestIdUsed($sessionId, $requestId));
    }

    public function testTerminateSession(): void
    {
        $sessionId = 'test-session-4';
        $requestId = 'req-456';

        $this->sessionManager->createSession($sessionId, []);
        $this->sessionManager->markRequestIdUsed($sessionId, $requestId);

        $this->assertNotNull($this->sessionManager->getSessionInfo($sessionId));
        $this->assertTrue($this->sessionManager->isRequestIdUsed($sessionId, $requestId));

        $this->sessionManager->terminateSession($sessionId);

        $this->assertNull($this->sessionManager->getSessionInfo($sessionId));
        $this->assertFalse($this->sessionManager->isRequestIdUsed($sessionId, $requestId));
    }

    public function testSessionCount(): void
    {
        $this->assertEquals(0, $this->sessionManager->getSessionCount());

        $this->sessionManager->createSession('session-1', []);
        $this->assertEquals(1, $this->sessionManager->getSessionCount());

        $this->sessionManager->createSession('session-2', []);
        $this->assertEquals(2, $this->sessionManager->getSessionCount());

        $this->sessionManager->terminateSession('session-1');
        $this->assertEquals(1, $this->sessionManager->getSessionCount());
    }

    public function testNonExistentSession(): void
    {
        $this->assertNull($this->sessionManager->getSessionInfo('non-existent'));
        $this->assertFalse($this->sessionManager->isSessionInitialized('non-existent'));
    }

    public function testGetAllSessions(): void
    {
        $this->assertEmpty($this->sessionManager->getAllSessions());

        $this->sessionManager->createSession('session-1', ['name' => 'client1']);
        $this->sessionManager->createSession('session-2', ['name' => 'client2']);

        $sessions = $this->sessionManager->getAllSessions();
        $this->assertCount(2, $sessions);
        $this->assertArrayHasKey('session-1', $sessions);
        $this->assertArrayHasKey('session-2', $sessions);
    }
}
