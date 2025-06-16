<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Auth;

class McpUser
{
    public function __construct(
        public readonly string  $id,
        public readonly array   $scopes = [],
        public readonly ?string $email = null,
        public readonly ?string $name = null,
    )
    {
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
