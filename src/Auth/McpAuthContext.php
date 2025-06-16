<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Auth;

class McpAuthContext
{
    public function __construct(
        public readonly McpOAuthToken $token,
        public readonly ?McpUser      $user = null,
    )
    {
    }
}
