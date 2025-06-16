<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface McpAuthMiddlewareInterface
{
    public function authenticate(ServerRequestInterface $request): ?McpAuthContext;

    public function requiresAuthentication(ServerRequestInterface $request): bool;

    public function handleUnauthorized(ServerRequestInterface $request): ResponseInterface;
}
