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

namespace Ripple\File\Lock;

use function Co\cancelForked;
use function Co\forked;
use function fclose;
use function file_exists;
use function flock;
use function fopen;
use function is_resource;
use function md5;
use function sys_get_temp_dir;
use function touch;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;

class Lock
{
    /*** @var resource|false */
    private mixed $resource;

    /*** @var string */
    private string $path;

    /*** @var string */
    private string $forkHandlerEventID;
    /**
     * @var bool
     */
    private bool $closed = false;

    /**
     * @param string $name
     */
    public function __construct(private readonly string $name = 'default')
    {
        $this->path = Lock::generateFilePathByChannelName($this->name);

        if (!file_exists($this->path)) {
            touch($this->path);
        }

        $this->resource = fopen($this->path, 'r');

        $this->forkHandlerEventID = forked(function () {
            fclose($this->resource);
            $this->resource = fopen($this->path, 'r');
        });
    }

    /**
     * @param string $name
     *
     * @return string
     */
    private static function generateFilePathByChannelName(string $name): string
    {
        $name = md5($name);
        return sys_get_temp_dir() . '/' . $name . '.lock';
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (is_resource($this->resource)) {
            fclose($this->resource);
        }

        cancelForked($this->forkHandlerEventID);
    }

    /**
     * @param bool $blocking
     *
     * @return bool
     */
    public function lock(bool $blocking = true): bool
    {
        return flock($this->resource, $blocking ? LOCK_EX : LOCK_EX | LOCK_NB);
    }

    /**
     * @param bool $blocking
     *
     * @return bool
     */
    public function sharedLock(bool $blocking = true): bool
    {
        return flock($this->resource, $blocking ? LOCK_SH : LOCK_SH | LOCK_NB);
    }

    /**
     * @return bool
     */
    public function unlock(): bool
    {
        return flock($this->resource, LOCK_UN);
    }
}
