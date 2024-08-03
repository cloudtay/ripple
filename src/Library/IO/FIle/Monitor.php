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

namespace Psc\Library\IO\FIle;

use Closure;
use DirectoryIterator;
use InvalidArgumentException;
use Psc\Core\Output;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use function call_user_func;
use function file_exists;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function P\cancel;
use function P\repeat;

class Monitor
{
    /**
     * @var Closure
     */
    public Closure $onTouch;

    /**
     * @var Closure
     */
    public Closure $onModify;

    /**
     * @var Closure
     */
    public Closure $onRemove;

    /**
     * @var array
     */
    private array $cache = array();

    /**
     * @var string
     */
    private string $timer1;

    /**
     * @var string
     */
    private string $timer2;

    /**
     * 1: file
     * 2: directory
     * @var int
     */
    private readonly int $monitorType;

    /**
     * @param string       $path
     * @param array|string $extensions
     * @param bool         $tree
     * @param bool         $withDirectory
     * @param int|float    $frequency
     */
    public function __construct(
        private readonly string       $path,
        private readonly array|string $extensions = '*',
        private readonly bool         $tree = true,
        private readonly bool         $withDirectory = false,
        private readonly int|float    $frequency = 1
    ) {
        if (is_file($this->path)) {
            $this->monitorType = 1;
        } elseif (is_dir($this->path)) {
            $this->monitorType = 2;
        } else {
            throw new InvalidArgumentException('Invalid path');
        }

        if ($this->monitorType === 1) {
            $this->foundFile(new SplFileInfo($this->path));
        } else {
            $this->tree($this->path);
        }
        $this->monitor();
    }

    /**
     * @param string $path
     * @return void
     */
    private function tree(string $path): void
    {
        $directory = $this->tree
            ? new RecursiveDirectoryIterator($path)
            : new DirectoryIterator($path);

        /**
         * @var SplFileInfo[] $iterator
         */
        $iterator = new RecursiveIteratorIterator($directory);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                if (!$this->withDirectory) {
                    continue;
                }
            } elseif ($fileInfo->isFile()) {
                $ext = $fileInfo->getExtension();
                if (is_string($this->extensions) && $this->extensions !== '*') {
                    if ($ext !== $this->extensions) {
                        continue;
                    }
                } elseif (is_array($this->extensions)) {
                    if (!in_array($ext, $this->extensions, true)) {
                        continue;
                    }
                }
            } else {
                continue;
            }
            $this->foundFile($fileInfo);
        }
    }

    /**
     * @param SplFileInfo $fileInfo
     * @return void
     */
    private function foundFile(SplFileInfo $fileInfo): void
    {
        $fullPath = $fileInfo->getRealPath();
        if (!isset($this->cache[$fullPath])) {
            $this->onTouch($fileInfo);
            $this->cache[$fullPath] = $fileInfo->getMTime();
        } elseif ($this->cache[$fullPath] !== $fileInfo->getMTime()) {
            $this->onChangeFile($fileInfo);
            $this->cache[$fullPath] = $fileInfo->getMTime();
        }
    }

    /**
     * @param SplFileInfo $info
     * @return void
     */
    private function onTouch(SplFileInfo $info): void
    {
        if (isset($this->onTouch)) {
            try {
                call_user_func($this->onTouch, $info->getRealPath());
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
        if (isset($this->onModify)) {
            try {
                call_user_func($this->onModify, $info->getRealPath());
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }
    }

    /**
     * @return void
     */
    private function monitor(): void
    {
        $this->timer1 = repeat(function () {
            if ($this->monitorType === 1) {
                $this->foundFile(new SplFileInfo($this->path));
            } else {
                $this->tree($this->path);
            }
        }, $this->frequency);

        $this->timer2 = repeat(function () {
            foreach ($this->cache as $file => $mtime) {
                if (!file_exists($file)) {
                    $this->onRemoveFile(new SplFileInfo($file));
                    unset($this->cache[$file]);
                }
            }
        }, $this->frequency);
    }

    /**
     * @param SplFileInfo $info
     * @return void
     */
    private function onRemoveFile(SplFileInfo $info): void
    {
        if (isset($this->onRemove)) {
            try {
                call_user_func($this->onRemove, $info->getRealPath());
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }
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

        $this->cache = array();
    }
}
