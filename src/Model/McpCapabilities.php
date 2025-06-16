<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Model;

use Soukicz\Mcp\McpSerializableInterface;
use stdClass;

class McpCapabilities implements McpSerializableInterface
{
    public function __construct(
        public readonly bool  $tools = false,
        public readonly bool  $prompts = false,
        public readonly bool  $resources = false,
        public readonly bool  $logging = false,
        public readonly array $experimental = [],
    )
    {
    }

    public function toArray(): array
    {
        $capabilities = [];

        if ($this->tools) {
            $capabilities['tools'] = new stdClass();
        }

        if ($this->prompts) {
            $capabilities['prompts'] = ['listChanged' => true];
        }

        if ($this->resources) {
            $capabilities['resources'] = ['subscribe' => true, 'listChanged' => true];
        }

        if ($this->logging) {
            $capabilities['logging'] = new stdClass();
        }

        if (!empty($this->experimental)) {
            $capabilities['experimental'] = $this->experimental;
        }

        return $capabilities;
    }
}
