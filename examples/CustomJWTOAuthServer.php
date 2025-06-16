<?php

declare(strict_types=1);

/**
 * Example custom OAuth implementation using simple JWT validation
 * 
 * This shows how to implement McpOAuthServerInterface for custom authentication
 * without requiring League OAuth2 Server or any specific library.
 */

namespace Example;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Soukicz\Mcp\Auth\McpAuthContext;
use Soukicz\Mcp\Auth\McpOAuthServerInterface;
use Soukicz\Mcp\Auth\McpOAuthToken;
use Soukicz\Mcp\Auth\McpUser;

class CustomJWTOAuthServer implements McpOAuthServerInterface
{
    public function __construct(
        private readonly string $jwtSecret,
        private readonly array $validClients = ['test-client' => 'test-secret'],
        private readonly array $publicEndpoints = ['/auth/token', '/health', '/ping']
    ) {
    }

    public function validateAccessToken(ServerRequestInterface $request): ?McpAuthContext
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        
        $token = substr($authHeader, 7);
        
        // Simple JWT validation (in production, use firebase/php-jwt or similar)
        if ($this->validateJWT($token)) {
            $payload = $this->decodeJWT($token);
            
            $user = new McpUser(
                id: $payload['sub'] ?? 'anonymous',
                scopes: $payload['scopes'] ?? ['read']
            );
            
            $oauthToken = new McpOAuthToken(
                accessToken: $token,
                tokenType: 'Bearer',
                scope: implode(' ', $payload['scopes'] ?? ['read'])
            );
            
            return new McpAuthContext($oauthToken, $user);
        }
        
        return null;
    }

    public function handleAuthorizationRequest(ServerRequestInterface $request): ResponseInterface
    {
        // Simple implementation - in production, show consent screen
        return new Response(200, ['Content-Type' => 'text/html'], 
            '<h1>Authorization Required</h1><p>This would be your consent screen.</p>'
        );
    }

    public function handleTokenRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getBody()->getContents();
        parse_str($body, $params);
        
        $grantType = $params['grant_type'] ?? '';
        $clientId = $params['client_id'] ?? '';
        $clientSecret = $params['client_secret'] ?? '';
        
        // Validate client
        if (!isset($this->validClients[$clientId]) || $this->validClients[$clientId] !== $clientSecret) {
            return new Response(401, ['Content-Type' => 'application/json'], 
                json_encode(['error' => 'invalid_client'])
            );
        }
        
        if ($grantType === 'client_credentials') {
            // Generate simple JWT token
            $token = $this->generateJWT([
                'sub' => $clientId,
                'iat' => time(),
                'exp' => time() + 3600, // 1 hour
                'scopes' => ['read', 'write', 'tools']
            ]);
            
            return new Response(200, ['Content-Type' => 'application/json'], 
                json_encode([
                    'access_token' => $token,
                    'token_type' => 'Bearer',
                    'expires_in' => 3600,
                    'scope' => 'read write tools'
                ])
            );
        }
        
        return new Response(400, ['Content-Type' => 'application/json'], 
            json_encode(['error' => 'unsupported_grant_type'])
        );
    }

    public function isPublicEndpoint(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        
        foreach ($this->publicEndpoints as $publicEndpoint) {
            if (str_starts_with($path, $publicEndpoint)) {
                return true;
            }
        }
        
        return false;
    }

    private function generateJWT(array $payload): string
    {
        // Simple JWT implementation (use proper library in production)
        $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = base64url_encode(json_encode($payload));
        $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", $this->jwtSecret, true));
        
        return "$header.$payload.$signature";
    }

    private function validateJWT(string $token): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        
        [$header, $payload, $signature] = $parts;
        $expectedSignature = base64url_encode(hash_hmac('sha256', "$header.$payload", $this->jwtSecret, true));
        
        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }
        
        $payloadData = json_decode(base64url_decode($payload), true);
        return isset($payloadData['exp']) && $payloadData['exp'] > time();
    }

    private function decodeJWT(string $token): array
    {
        $parts = explode('.', $token);
        return json_decode(base64url_decode($parts[1]), true) ?: [];
    }
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}