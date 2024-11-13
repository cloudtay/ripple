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

namespace Ripple\File;

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

                    $this->add("{$path}/{$item}");
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
}
