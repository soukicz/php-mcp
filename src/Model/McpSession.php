<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Model;


use DateTimeImmutable;

class McpSession
{
    public function __construct(
        public readonly string             $id,
        public readonly string             $protocolVersion,
        public readonly array              $clientCapabilities,
        public readonly DateTimeImmutable $createdAt,
        public readonly bool               $initialized = false,
    )
    {
    }

    public static function create(string $protocolVersion, array $clientCapabilities): self
    {
        return new self(
            id: uniqid('mcp_', true),
            protocolVersion: $protocolVersion,
            clientCapabilities: $clientCapabilities,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function withInitialized(): self
    {
        return new self(
            id: $this->id,
            protocolVersion: $this->protocolVersion,
            clientCapabilities: $this->clientCapabilities,
            createdAt: $this->createdAt,
            initialized: true,
        );
    }
}
