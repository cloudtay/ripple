<?php

declare(strict_types=1);
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

namespace Psc\Store\IO;

use Closure;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Output;
use Psc\Core\StoreAbstract;
use Psc\Core\Stream\Stream;
use Psc\Std\Stream\Exception\Exception;
use SplFileInfo;
use Throwable;

use function fopen;
use function P\cancel;
use function P\promise;
use function P\repeat;

/**
 *
 */
class File extends StoreAbstract
{
    /**
     * @var StoreAbstract
     */
    protected static StoreAbstract $instance;

    /**
     * @param string $path
     * @return Promise
     */
    public function getContents(string $path): Promise
    {
        return promise(function (Closure $r, Closure $d) use ($path) {
            if (!$resource = fopen($path, 'r')) {
                $d(new Exception('Failed to open file: ' . $path));
                return;
            }

            $stream = new Stream($resource);
            $stream->setBlocking(false);
            $content = '';

            $stream->onReadable(function (Stream $stream) use ($r, $d, &$content) {
                $fragment = $stream->read(8192);
                if ($fragment === '') {
                    $stream->close();
                    $r($content);
                    return;
                }

                $content .= $fragment;

                if ($stream->eof()) {
                    $stream->close();
                    $r($content);
                }
            });
        });
    }

    /**
     * @param string $path
     * @param string $mode
     * @return Stream
     */
    public function open(string $path, string $mode): Stream
    {
        return new Stream(fopen($path, $mode));
    }


    /**
     * @param string $path
     * @return object
     */
    public function watch(string $path): object
    {
        return new class ($path) {
            public Closure $onNewFile;
            public Closure $onChangeFile;
            public Closure $onRemoveFile;

            private array $files = [];

            /**
             * @param string $path
             */
            public function __construct(private readonly string $path)
            {
                $this->tree($this->path);
            }

            /**
             * @param string $path
             * @return void
             */
            private function tree(string $path): void
            {
                foreach (scandir($path) as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }

                    $fullPath = "{$path}/{$file}";

                    if (is_dir($fullPath)) {
                        $this->tree($fullPath);
                    } else {

                        if (!isset($this->files[$fullPath])) {
                            $this->onNewFile(new SplFileInfo($fullPath));
                        } else {
                            $info = new SplFileInfo($fullPath);
                            if ($this->files[$fullPath] !== $info->getMTime()) {
                                $this->onChangeFile($info);
                            }
                        }
                    }
                }
            }

            /**
             * @param SplFileInfo $info
             * @return void
             */
            private function onNewFile(SplFileInfo $info): void
            {
                $this->files[$info->getPathname()] = $info->getMTime();
                if (isset($this->onNewFile)) {
                    try {
                        call_user_func($this->onNewFile, $info->getPathname());
                    } catch (Throwable $e) {
                        Output::error($e->getMessage());
                    }
                }
            }

            /**
             * @param SplFileInfo $info
             * @return void
             */
            private function onChangeFile(SplFileInfo $info): void
            {
                $this->files[$info->getPathname()] = $info->getMTime();
                if (isset($this->onChangeFile)) {
                    try {
                        call_user_func($this->onChangeFile, $info->getPathname());
                    } catch (Throwable $e) {
                        Output::error($e->getMessage());
                    }
                }
            }

            private string $timer1;
            private string $timer2;

            /**
             * @return void
             */
            public function listen(): void
            {
                $this->timer1 = repeat(function () {
                    $this->tree($this->path);
                }, 1);

                $this->timer2 = repeat(function () {
                    foreach ($this->files as $file => $mtime) {
                        if (!file_exists($file)) {
                            unset($this->files[$file]);
                            if (isset($this->onRemoveFile)) {
                                try {
                                    call_user_func($this->onRemoveFile, $file);
                                } catch (Throwable $e) {
                                    Output::error($e->getMessage());
                                }
                            }
                        }
                    }
                }, 1);
            }

            /**
             * @return void
             */
            public function stop(): void
            {
                if (isset($this->timer1)) {
                    cancel($this->timer1);
                }

                if (isset($this->timer2)) {
                    cancel($this->timer2);
                }

                $this->files = [];
            }
        };
    }
}
