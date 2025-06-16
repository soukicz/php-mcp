<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface McpOAuthServerInterface
{
    /**
     * Validate an access token and return authentication context
     * 
     * @param ServerRequestInterface $request Request containing Authorization header
     * @return McpAuthContext|null Authentication context if token is valid, null otherwise
     */
    public function validateAccessToken(ServerRequestInterface $request): ?McpAuthContext;

    /**
     * Handle authorization request (e.g., GET /auth/authorize)
     * 
     * @param ServerRequestInterface $request Authorization request
     * @return ResponseInterface Authorization response (redirect or consent form)
     */
    public function handleAuthorizationRequest(ServerRequestInterface $request): ResponseInterface;

    /**
     * Handle token request (e.g., POST /auth/token)
     * 
     * @param ServerRequestInterface $request Token request (authorization_code, refresh_token, etc.)
     * @return ResponseInterface Token response or error
     */
    public function handleTokenRequest(ServerRequestInterface $request): ResponseInterface;

    /**
     * Check if the given endpoint should be public (no authentication required)
     * 
     * @param ServerRequestInterface $request
     * @return bool True if endpoint is public, false if authentication required
     */
    public function isPublicEndpoint(ServerRequestInterface $request): bool;
}