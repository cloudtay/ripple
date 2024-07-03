<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Core\Stream;

use Closure;
use Psc\Core\Standard\StreamInterface;
use function call_user_func;
use function fclose;
use function feof;
use function fflush;
use function fgetcsv;
use function fgets;
use function fread;
use function fseek;
use function ftell;
use function ftruncate;
use function fwrite;
use function get_resource_id;
use function rewind;
use function stream_get_contents;
use function stream_get_meta_data;
use function stream_set_blocking;

class Stream implements StreamInterface
{
    /**
     * @var resource
     */
    public mixed $stream;

    /**
     * @var int $id
     */
    public int $id;

    /**
     * @var array $meta
     */
    public array $meta;

    /**
     * @var array
     */
    private array $onCloseCallbacks = [];

    /**
     * Stream constructor.
     * @param resource $resource
     */
    public function __construct(mixed $resource)
    {
        $this->stream = $resource;
        $this->meta   = stream_get_meta_data($resource);
        $this->id     = get_resource_id($resource);
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
        foreach ($this->onCloseCallbacks as $callback) {
            call_user_func($callback);
        }
    }

    /**
     * @param int|null $length
     * @return string
     */
    public function read(int|null $length): string
    {
        return fread($this->stream, $length);
    }

    /**
     * @param string $string
     * @return int
     */
    public function write(string $string): int
    {
        return fwrite($this->stream, $string);
    }

    /**
     * @return bool
     */
    public function eof(): bool
    {
        return feof($this->stream);
    }

    /**
     * 移动指定位置指针
     * @param int $offset
     * @param int $whence
     * @return void
     */
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        fseek($this->stream, $offset, $whence);
    }

    /**
     * @param bool $bool
     * @return bool
     */
    public function setBlocking(bool $bool): bool
    {
        return stream_set_blocking($this->stream, $bool);
    }

    /**
     * @param Closure $closure
     * @return void
     */
    public function onClose(Closure $closure): void
    {
        $this->onCloseCallbacks[] = $closure;
    }

    /**
     * @return int|false
     */
    public function ftell(): int|false
    {
        return ftell($this->stream);
    }

    /**
     * @return string|false
     */
    public function fgets(): string|false
    {
        return fgets($this->stream);
    }

    /**
     * @return array|false
     */
    public function fgetcsv(): array|false
    {
        return fgetcsv($this->stream);
    }

    /**
     * @param int $size
     * @return bool
     */
    public function ftruncate(int $size): bool
    {
        return ftruncate($this->stream, $size);
    }

    /**
     * @return int|false
     */
    public function fflush(): int|false
    {
        return fflush($this->stream);
    }

    /**
     * @param string $data
     * @return int|false
     */
    public function fwrite(string $data): int|false
    {
        return fwrite($this->stream, $data);
    }

    /**
     * @param int $length
     * @return string|false
     */
    public function fread(int $length): string|false
    {
        return fread($this->stream, $length);
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
        return $this->meta['size'] ?? null;
    }

    /**
     * @return int
     */
    public function tell(): int
    {
        return $this->ftell();
    }

    /**
     * @return bool
     */
    public function isSeekable(): bool
    {
        return $this->meta['seekable'] ?? false;
    }

    /**
     * @return bool
     */
    public function isWritable(): bool
    {
        return $this->meta['mode'][0] === 'w' || $this->meta['mode'][0] === 'a' || $this->meta['mode'][0] === 'x' || $this->meta['mode'][0] === 'c';
    }

    /**
     * @return bool
     */
    public function isReadable(): bool
    {
        return $this->meta['mode'][0] === 'r' || $this->meta['mode'][0] === 'r+';
    }

    /**
     * @return string
     */
    public function getContents(): string
    {
        return stream_get_contents($this->stream);
    }

    /**
     * @param string|null $key
     * @return mixed
     */
    public function getMetadata(?string $key = null): mixed
    {
        return $key ? $this->meta[$key] : $this->meta;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->getContents();
    }
}
