<?php

declare(strict_types=1);

/**
 * Example implementation of McpOAuthServerInterface for League OAuth2 Server
 * 
 * This shows how to adapt League OAuth2 Server to work with the MCP library.
 * Include this file in your project if you want to use League OAuth2.
 */

namespace Example;

use GuzzleHttp\Psr7\Response;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Soukicz\Mcp\Auth\McpAuthContext;
use Soukicz\Mcp\Auth\McpOAuthServerInterface;
use Soukicz\Mcp\Auth\McpOAuthToken;
use Soukicz\Mcp\Auth\McpUser;

class LeagueOAuthServerAdapter implements McpOAuthServerInterface
{
    public function __construct(
        private readonly ?AuthorizationServer $authorizationServer = null,
        private readonly ?ResourceServer $resourceServer = null,
        private readonly array $publicEndpoints = ['/auth/authorize', '/auth/callback', '/auth/token', '/auth/revoke'],
    ) {
    }

    public function validateAccessToken(ServerRequestInterface $request): ?McpAuthContext
    {
        if ($this->resourceServer === null) {
            return null;
        }

        try {
            $authenticatedRequest = $this->resourceServer->validateAuthenticatedRequest($request);
            
            // Extract information from validated request
            $userId = $authenticatedRequest->getAttribute('oauth_user_id', 'anonymous');
            $scopes = $authenticatedRequest->getAttribute('oauth_scopes', []);
            
            // Extract token from Authorization header
            $authHeader = $request->getHeaderLine('Authorization');
            $accessToken = null;
            if (str_starts_with($authHeader, 'Bearer ')) {
                $accessToken = substr($authHeader, 7);
            }

            if ($accessToken === null) {
                return null;
            }

            $user = new McpUser($userId, $scopes);
            $token = new McpOAuthToken(
                accessToken: $accessToken,
                tokenType: 'Bearer',
                scope: implode(' ', $scopes)
            );

            return new McpAuthContext($token, $user);
        } catch (OAuthServerException) {
            return null;
        } catch (\Exception) {
            return null;
        }
    }

    public function handleAuthorizationRequest(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->authorizationServer === null) {
            return new Response(501, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'not_implemented',
                'error_description' => 'Authorization server not configured'
            ]));
        }

        try {
            $authRequest = $this->authorizationServer->validateAuthorizationRequest($request);
            
            // Auto-approve for example - in production, show consent screen
            $authRequest->setAuthorizationApproved(true);
            
            return $this->authorizationServer->completeAuthorizationRequest($authRequest, new Response());
        } catch (OAuthServerException $exception) {
            return $exception->generateHttpResponse(new Response());
        } catch (\Exception $exception) {
            return new Response(500, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'server_error',
                'error_description' => $exception->getMessage()
            ]));
        }
    }

    public function handleTokenRequest(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->authorizationServer === null) {
            return new Response(501, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'not_implemented', 
                'error_description' => 'Authorization server not configured'
            ]));
        }

        try {
            return $this->authorizationServer->respondToAccessTokenRequest($request, new Response());
        } catch (OAuthServerException $exception) {
            return $exception->generateHttpResponse(new Response());
        } catch (\Exception $exception) {
            return new Response(500, ['Content-Type' => 'application/json'], json_encode([
                'error' => 'server_error',
                'error_description' => $exception->getMessage()
            ]));
        }
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
}