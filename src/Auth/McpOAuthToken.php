<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Auth;

use DateTimeImmutable;

class McpOAuthToken
{
    public function __construct(
        public readonly string             $accessToken,
        public readonly string             $tokenType,
        public readonly ?int               $expiresIn = null,
        public readonly ?string            $refreshToken = null,
        public readonly ?array             $scopes = null,
        public readonly ?DateTimeImmutable $issuedAt = null,
        public readonly ?string            $scope = null,
    )
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: $data['access_token'],
            tokenType: $data['token_type'] ?? 'Bearer',
            expiresIn: isset($data['expires_in']) ? (int)$data['expires_in'] : null,
            refreshToken: $data['refresh_token'] ?? null,
            scopes: isset($data['scope']) ? explode(' ', $data['scope']) : null,
            issuedAt: new DateTimeImmutable(),
            scope: $data['scope'] ?? null,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
            'refresh_token' => $this->refreshToken,
            'scope' => $this->scopes ? implode(' ', $this->scopes) : null,
        ], static fn($value) => $value !== null);
    }

    public function isExpired(): bool
    {
        if ($this->expiresIn === null || $this->issuedAt === null) {
            return false;
        }

        $expirationTime = $this->issuedAt->modify("+{$this->expiresIn} seconds");
        return new DateTimeImmutable() >= $expirationTime;
    }

    public function getAuthorizationHeader(): string
    {
        return "{$this->tokenType} {$this->accessToken}";
    }
}
