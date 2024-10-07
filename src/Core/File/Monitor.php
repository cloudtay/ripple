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

namespace Psc\Core\File;

use Closure;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function Co\cancel;
use function Co\repeat;
use function file_exists;
use function in_array;
use function is_dir;
use function is_file;
use function realpath;
use function scandir;

class Monitor
{
    public const TOUCH  = 'touch';
    public const MODIFY = 'modify';

    /*** @var Closure */
    public Closure $onTouch;

    /*** @var Closure */
    public Closure $onModify;

    /*** @var Closure */
    public Closure $onRemove;

    /*** @var array */
    private array $cache = array();

    /*** @var string */
    private string $timer1;

    /*** @var string */
    private string $timer2;

    /*** @var array */
    private array $list = [];

    /**
     * Monitor constructor.
     */
    public function __construct()
    {
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/26 21:27
     *
     * @param string     $path
     * @param array|null $ext
     *
     * @return void
     */
    public function add(string $path, array|null $ext = null): void
    {
        $path              = realpath($path);
        $this->list[$path] = $ext;

        if (is_file($path)) {
            $this->cache[$path] = (new SplFileInfo($path))->getMTime();
        }

        if (is_dir($path)) {
            $directory = new RecursiveDirectoryIterator($path);
            $iterator  = new RecursiveIteratorIterator($directory);

            if ($ext === null) {
                $this->cache[$path] = (new SplFileInfo($path))->getMTime();
                $scan               = scandir($path);
                foreach ($scan as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }

                    if (is_dir("{$path}/{$item}")) {
                        $this->add("{$path}/{$item}");
                    }
                }
            } else {
                /**
                 * @var SplFileInfo $item
                 */
                foreach ($iterator as $item) {
                    if ($item->getBasename() === '..') {
                        continue;
                    }

                    if ($item->isFile() && in_array($item->getExtension(), $ext, true)) {
                        $this->cache[$item->getRealPath()] = $item->getMTime();
                    }
                }
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/26 21:51
     * @return void
     */
    public function start(): void
    {
        $this->timer1 = repeat(fn () => $this->tick(), 1);
        $this->timer2 = repeat(fn () => $this->inspector(), 1);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/26 21:29
     * @return void
     */
    private function tick(): void
    {
        foreach ($this->list as $path => $ext) {
            if (is_file($path)) {
                $fileInfo = new SplFileInfo($path);
                if (!isset($this->cache[$path])) {
                    $this->onEvent($fileInfo, Monitor::TOUCH);
                } elseif ($this->cache[$path] !== $fileInfo->getMTime()) {
                    $this->onEvent($fileInfo, Monitor::MODIFY);
                }
            } elseif (is_dir($path)) {
                if ($ext === null) {
                    $info = new SplFileInfo($path);
                    if (!isset($this->cache[$path])) {
                        $this->onEvent($info, Monitor::TOUCH);
                    } elseif ($this->cache[$path] !== $info->getMTime()) {
                        $this->onEvent($info, Monitor::MODIFY);
                    }
                } else {
                    $directory = new RecursiveDirectoryIterator($path);
                    $iterator  = new RecursiveIteratorIterator($directory);
                    /**
                     * @var SplFileInfo $item
                     */
                    foreach ($iterator as $item) {
                        if ($item->getBasename() === '..') {
                            continue;
                        }
                        if ($item->isFile() && in_array($item->getExtension(), $ext, true)) {
                            if (!isset($this->cache[$item->getRealPath()])) {
                                $this->onEvent($item, Monitor::TOUCH);
                            } elseif ($this->cache[$item->getRealPath()] !== $item->getMTime()) {
                                $this->onEvent($item, Monitor::MODIFY);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/26 21:45
     *
     * @param SplFileInfo $fileInfo
     * @param string      $event
     *
     * @return void
     */
    private function onEvent(SplFileInfo $fileInfo, string $event): void
    {
        switch ($event) {
            case Monitor::TOUCH:
                if (isset($this->onTouch)) {
                    ($this->onTouch)($fileInfo->getRealPath());
                    $this->cache[$fileInfo->getRealPath()] = $fileInfo->getMTime();
                }
                break;

            case Monitor::MODIFY:
                if (isset($this->onModify)) {
                    ($this->onModify)($fileInfo->getRealPath());
                    $this->cache[$fileInfo->getRealPath()] = $fileInfo->getMTime();
                }
                break;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/26 21:52
     * @return void
     */
    private function inspector(): void
    {
        foreach ($this->cache as $path => $time) {
            if (!file_exists($path)) {
                if (isset($this->onRemove)) {
                    ($this->onRemove)($path);
                    unset($this->cache[$path]);
                }

                if (isset($this->list[$path])) {
                    unset($this->list[$path]);
                }
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/26 21:53
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
    }

    /**
     * @Description Please use `start` method
     * @Author cclilshy
     * @Date   2024/10/7 17:57
     * @return void
     */
    public function run(): void
    {
        $this->start();
    }
}
