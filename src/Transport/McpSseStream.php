<?php

declare(strict_types=1);

namespace Soukicz\Mcp\Transport;

use Psr\Http\Message\StreamInterface;
use RuntimeException;

class McpSseStream implements StreamInterface
{
    private string $buffer = '';
    private int $position = 0;
    private bool $closed = false;

    public function writeEvent(McpSseEvent $event): void
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot write to closed stream');
        }

        $sseData = '';

        if ($event->id !== null) {
            $sseData .= "id: {$event->id}\n";
        }

        if ($event->event !== null) {
            $sseData .= "event: {$event->event}\n";
        }

        if ($event->data !== null) {
            foreach (explode("\n", $event->data) as $line) {
                $sseData .= "data: {$line}\n";
            }
        }

        $sseData .= "\n";
        $this->buffer .= $sseData;
    }

    public function __toString(): string
    {
        return $this->buffer;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        $this->buffer = '';
        $this->position = 0;
        return null;
    }

    public function getSize(): ?int
    {
        return strlen($this->buffer);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        switch ($whence) {
            case SEEK_SET:
                $this->position = $offset;
                break;
            case SEEK_CUR:
                $this->position += $offset;
                break;
            case SEEK_END:
                $this->position = strlen($this->buffer) + $offset;
                break;
        }

        $this->position = max(0, min($this->position, strlen($this->buffer)));
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return !$this->closed;
    }

    public function write(string $string): int
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot write to closed stream');
        }

        $this->buffer .= $string;
        return strlen($string);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $data = substr($this->buffer, $this->position, $length);
        $this->position += strlen($data);
        return $data;
    }

    public function getContents(): string
    {
        $data = substr($this->buffer, $this->position);
        $this->position = strlen($this->buffer);
        return $data;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $metadata = [
            'timed_out' => false,
            'blocked' => true,
            'eof' => $this->eof(),
            'wrapper_type' => 'SSE',
            'stream_type' => 'MEMORY',
            'mode' => 'r+',
            'unread_bytes' => 0,
            'seekable' => true,
            'uri' => 'php://memory',
        ];

        if ($key === null) {
            return $metadata;
        }

        return $metadata[$key] ?? null;
    }
}

