<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Auth;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class McpGenericOAuthMiddleware implements McpAuthMiddlewareInterface
{
    public function __construct(
        private readonly McpOAuthServerInterface $oauthServer
    ) {
    }

    public function authenticate(ServerRequestInterface $request): ?McpAuthContext
    {
        return $this->oauthServer->validateAccessToken($request);
    }

    public function requiresAuthentication(ServerRequestInterface $request): bool
    {
        return !$this->oauthServer->isPublicEndpoint($request);
    }

    public function handleUnauthorized(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(401, ['Content-Type' => 'application/json'], json_encode([
            'error' => 'unauthorized',
            'error_description' => 'Valid access token required'
        ], JSON_THROW_ON_ERROR));
    }

    public function handleAuthorizationRequest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->oauthServer->handleAuthorizationRequest($request);
    }

    public function handleTokenRequest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->oauthServer->handleTokenRequest($request);
    }
}
