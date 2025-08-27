<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Stream;

use Ripple\Stream\Exception\ConnectionException;

use function fclose;
use function feof;
use function fread;
use function fseek;
use function fstat;
use function ftell;
use function fwrite;
use function is_resource;
use function rewind;
use function stream_get_contents;
use function stream_get_meta_data;
use function boolval;

use const SEEK_SET;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:37
 */
class Stream implements StreamInterface
{
    /**
     * @var resource
     */
    public readonly mixed $stream;

    /**
     * Stream constructor.
     *
     * @param resource $resource
     */
    public function __construct(mixed $resource)
    {
        $this->stream = $resource;
    }

    /**
     * @param int $length
     *
     * @return string
     * @throws ConnectionException
     */
    public function read(int $length): string
    {
        $content = @fread($this->stream, $length);
        if ($content === false) {
            $this->close();
            throw new ConnectionException('Unable to read from stream', ConnectionException::CONNECTION_READ_FAIL);
        }
        return $content;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if (!is_resource($this->stream)) {
            return;
        }
        fclose($this->stream);
    }

    /**
     * @param string $string
     *
     * @return int
     * @throws ConnectionException
     */
    public function write(string $string): int
    {
        $result = @fwrite($this->stream, $string);
        if ($result === false) {
            $this->close();
            throw new ConnectionException('Unable to write to stream', ConnectionException::CONNECTION_WRITE_FAIL);
        }
        return $result;
    }

    /**
     * @return bool
     */
    public function eof(): bool
    {
        return feof($this->stream);
    }

    /**
     * Move the pointer at the specified position
     *
     * @param int $offset
     * @param int $whence
     *
     * @return void
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        fseek($this->stream, $offset, $whence);
    }

    /**
     * @return void
     */
    public function rewind(): void
    {
        rewind($this->stream);
    }

    /**
     * @return mixed
     */
    public function detach(): mixed
    {
        if (!isset($this->stream)) {
            return null;
        }

        $result = $this->stream;
        unset($this->stream);
        return $result;
    }

    /**
     * @return int|null
     */
    public function getSize(): int|null
    {
        $stats = fstat($this->stream);
        return $stats['size'];
    }

    /**
     * @return int
     */
    public function tell(): int
    {
        return ftell($this->stream);
    }

    /**
     * @return bool
     */
    public function isSeekable(): bool
    {
        return boolval($this->getMetadata('seekable')) ?? false;
    }

    /**
     * @return bool
     */
    public function isWritable(): bool
    {
        $meta = $this->getMetadata('mode');
        return $meta[0] === 'w' ||
               $meta[0] === 'a' ||
               $meta[0] === 'x' ||
               $meta[0] === 'c';
    }

    /**
     * @return bool
     */
    public function isReadable(): bool
    {
        $meta = $this->getMetadata('mode');
        return $meta[0] === 'r' || $meta[0] === 'r+';
    }

    /**
     * @param string|null $key
     *
     * @return mixed
     */
    public function getMetadata(string|null $key = null): mixed
    {
        $meta = stream_get_meta_data($this->stream);
        return $key ? $meta[$key] : $meta;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->getContents();
    }

    /**
     * @return string
     */
    public function getContents(): string
    {
        return stream_get_contents($this->stream);
    }
}
