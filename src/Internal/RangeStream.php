<?php

declare(strict_types=1);

namespace Reedapt\Internal;

use Psr\Http\Message\StreamInterface;

/**
 * A read-only PSR-7 stream over `$length` bytes of an open resource, starting at
 * `$offset`. It reads from the file as the HTTP client sends, so a part of any
 * size uses no more memory than the client's read buffer.
 *
 * @internal
 */
final class RangeStream implements StreamInterface
{
    private int $position = 0;

    /** @param resource $resource */
    public function __construct(private $resource, private readonly int $offset, private readonly int $length)
    {
    }

    public function __toString(): string
    {
        try {
            $this->rewind();

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        // The caller owns the resource.
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= $this->length;
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->length + $offset,
            default => throw new \InvalidArgumentException('Invalid whence.'),
        };
        if ($target < 0 || $target > $this->length) {
            throw new \RuntimeException('Cannot seek outside the part.');
        }
        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('The stream is read-only.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $length = min($length, $this->length - $this->position);
        if ($length <= 0) {
            return '';
        }
        if (fseek($this->resource, $this->offset + $this->position) !== 0) {
            throw new \RuntimeException('The file could not be read.');
        }
        $data = fread($this->resource, $length);
        if ($data === false || $data === '') {
            throw new \RuntimeException('The file got shorter while it was uploading.');
        }
        $this->position += strlen($data);

        return $data;
    }

    public function getContents(): string
    {
        $out = '';
        while (!$this->eof()) {
            $out .= $this->read(1 << 20);
        }

        return $out;
    }

    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
