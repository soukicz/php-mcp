<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Tests\Auth;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Soukicz\Mcp\Auth\McpAuthContext;
use Soukicz\Mcp\Auth\McpOAuthToken;
use Soukicz\Mcp\Auth\McpUser;

class McpOAuthTest extends TestCase
{

    public function testOAuthTokenCreation(): void
    {
        $tokenData = [
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
            'refresh_token' => 'test-refresh-token',
            'scope' => 'read write'
        ];

        $token = McpOAuthToken::fromArray($tokenData);

        $this->assertEquals('test-access-token', $token->accessToken);
        $this->assertEquals('Bearer', $token->tokenType);
        $this->assertEquals(3600, $token->expiresIn);
        $this->assertEquals('test-refresh-token', $token->refreshToken);
        $this->assertEquals(['read', 'write'], $token->scopes);
    }

    public function testTokenExpiration(): void
    {
        $token = new McpOAuthToken(
            accessToken: 'test-token',
            tokenType: 'Bearer',
            expiresIn: 1,
            issuedAt: new DateTimeImmutable('-2 seconds')
        );

        $this->assertTrue($token->isExpired());
    }

    public function testTokenNotExpired(): void
    {
        $token = new McpOAuthToken(
            accessToken: 'test-token',
            tokenType: 'Bearer',
            expiresIn: 3600,
            issuedAt: new DateTimeImmutable()
        );

        $this->assertFalse($token->isExpired());
    }

    public function testTokenWithoutExpiration(): void
    {
        $token = new McpOAuthToken(
            accessToken: 'test-token',
            tokenType: 'Bearer'
        );

        $this->assertFalse($token->isExpired());
    }

    public function testAuthorizationHeader(): void
    {
        $token = new McpOAuthToken(
            accessToken: 'test-access-token',
            tokenType: 'Bearer'
        );

        $this->assertEquals('Bearer test-access-token', $token->getAuthorizationHeader());
    }


    public function testUserCreation(): void
    {
        $user = new McpUser(
            id: 'user123',
            scopes: ['read', 'write'],
            email: 'user@example.com',
            name: 'Test User'
        );

        $this->assertEquals('user123', $user->id);
        $this->assertTrue($user->hasScope('read'));
        $this->assertTrue($user->hasScope('write'));
        $this->assertFalse($user->hasScope('admin'));
        $this->assertEquals('user@example.com', $user->email);
    }

    public function testAuthContext(): void
    {
        $token = new McpOAuthToken(
            accessToken: 'test-token',
            tokenType: 'Bearer'
        );

        $user = new McpUser('user123', ['read']);

        $context = new McpAuthContext($token, $user);

        $this->assertEquals($token, $context->token);
        $this->assertEquals($user, $context->user);
    }
}
