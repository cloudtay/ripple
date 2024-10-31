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

namespace Ripple;

use Closure;
use Co\Base;
use Ripple\File\Exception\FileException;
use Ripple\File\Monitor;
use Throwable;

use function array_shift;
use function Co\forked;
use function Co\promise;
use function fopen;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:35
 */
class File extends Base
{
    /**
     * @var Base
     */
    protected static Base $instance;
    /**
     * @var Monitor[]
     */
    private array $monitors = array();

    public function __construct()
    {
        $this->registerOnFork();
    }

    /**
     * @return void
     */
    private function registerOnFork(): void
    {
        forked(function () {
            while ($monitor = array_shift($this->monitors)) {
                $monitor->stop();
            }
            $this->registerOnFork();
        });
    }

    /**
     * @param string $path
     *
     * @return string
     * @throws FileException
     */
    public function getContents(string $path): string
    {
        try {
            return promise(static function (Closure $resolve, Closure $reject) use ($path) {
                if (!$resource = fopen($path, 'r')) {
                    $reject(new FileException('Failed to open file: ' . $path));
                    return;
                }

                $stream = new Stream($resource);
                $stream->setBlocking(false);
                $content = '';

                $stream->onReadable(static function (Stream $stream) use ($resolve, $reject, &$content) {
                    $fragment = '';
                    while ($buffer = $stream->read(8192)) {
                        $fragment .= $buffer;
                    }

                    if ($fragment === '') {
                        if ($stream->eof()) {
                            $stream->close();
                            $resolve($content);
                        }
                        return;
                    }

                    $content .= $fragment;

                    if ($stream->eof()) {
                        $stream->close();
                        $resolve($content);
                    }
                });
            })->await();
        } catch (Throwable $e) {
            throw new FileException($e->getMessage());
        }
    }

    /**
     * @param string $path
     * @param string $mode
     *
     * @return Stream
     */
    public function open(string $path, string $mode): Stream
    {
        return new Stream(fopen($path, $mode));
    }

    /**
     * @return Monitor
     */
    public function watch(): Monitor
    {
        $this->monitors[] = $monitor = new Monitor();
        return $monitor;
    }
}
