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
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use InvalidArgumentException;
use RuntimeException;
use Exception;

use function Co\cancel;
use function Co\repeat;
use function file_exists;
use function in_array;
use function is_dir;
use function is_file;
use function realpath;
use function scandir;
use function is_readable;
use function max;
use function getmypid;
use function function_exists;

use const DIRECTORY_SEPARATOR;

class Monitor
{
    public const TOUCH  = 'touch';
    public const MODIFY = 'modify';
    public const REMOVE = 'remove';

    /*** @var int 默认检查间隔（秒） */
    private int $interval = 1;

    /*** @var Closure|null */
    public Closure|null $onTouch = null;

    /*** @var Closure|null */
    public Closure|null $onModify = null;

    /*** @var Closure|null */
    public Closure|null $onRemove = null;

    /*** @var array<string, int> 文件路径 => 最后修改时间 */
    private array $cache = array();

    /*** @var string|null 监控定时器ID */
    private string|null $timer1 = null;

    /*** @var string|null 检查删除定时器ID */
    private string|null $timer2 = null;

    /*** @var array<string, array|null> 监控的路径 => 文件扩展名 */
    private array $list = [];

    /*** @var int 启动监听的进程PID */
    private int $startPid = 0;

    /*** @var bool 是否已经启动 */
    private bool $running = false;

    /**
     * Monitor constructor.
     *
     * @param int $interval 检查间隔（秒）
     */
    public function __construct(int $interval = 1)
    {
        $this->interval = max(1, $interval);
    }

    /**
     * 添加要监控的路径
     *
     * @param string     $path 文件或目录的路径
     * @param array|null $ext  要监控的文件扩展名，null 表示所有文件
     *
     * @return void
     * @throws InvalidArgumentException 如果路径不存在或不可读
     */
    public function add(string $path, array|null $ext = null): void
    {
        $realPath = $this->normalizePath($path);

        if (!$realPath) {
            throw new InvalidArgumentException("Path does not exist or is not readable: {$path}");
        }

        $this->list[$realPath] = $ext;

        if (is_file($realPath)) {
            $this->cache[$realPath] = (new SplFileInfo($realPath))->getMTime();
            return;
        }

        if (is_dir($realPath)) {
            $this->scanDirectory($realPath, $ext);
        }
    }

    /**
     * 停止文件监控
     *
     * @return void
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        if (!function_exists('Co\\cancel')) {
            $this->running = false;
            return;
        }

        if ($this->timer1 !== null) {
            try {
                cancel($this->timer1);
            } catch (Exception $e) {

            }
            $this->timer1 = null;
        }

        if ($this->timer2 !== null) {
            try {
                cancel($this->timer2);
            } catch (Exception $e) {

            }
            $this->timer2 = null;
        }

        $this->running = false;
    }

    /**
     * 保留原有方法名称以保持兼容性
     *
     * @return void
     * @throws RuntimeException 如果协程环境不支持
     */
    public function run(): void
    {
        $this->start();
    }

    /**
     * 开始文件监控
     *
     * @return void
     * @throws RuntimeException 如果没有设置任何回调函数或协程环境不支持
     */
    public function start(): void
    {

        $isInChildProcess = $this->isInChildProcess();
        if ($isInChildProcess) {
            $this->stop();
            $this->startPid = getmypid();

            return;
        }


        if ($this->running && $this->startPid === getmypid()) {
            return;
        }

        if (!$this->onTouch && !$this->onModify && !$this->onRemove) {
            throw new RuntimeException("At least one event handler must be set before starting the monitor");
        }

        $this->stop();

        try {
            $this->timer1   = repeat(fn () => $this->tick(), $this->interval);
            $this->timer2   = repeat(fn () => $this->inspector(), $this->interval);
            $this->running  = true;
            $this->startPid = getmypid();
        } catch (Exception $e) {
            throw new RuntimeException("Failed to start monitor: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 检查是否在子进程中
     *
     * @return bool
     */
    private function isInChildProcess(): bool
    {
        if ($this->startPid === 0) {
            return false;
        }


        if ($this->startPid !== getmypid()) {
            return true;
        }

        return false;
    }

    /**
     * 检查监控器是否正在运行
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        if (!$this->running) {
            return false;
        }


        if ($this->isInChildProcess()) {
            $this->running = false;
            return false;
        }

        return true;
    }

    /**
     * 规范化路径，确保跨平台兼容性
     *
     * @param string $path
     *
     * @return string|false
     */
    private function normalizePath(string $path): string|false
    {
        $realPath = realpath($path);

        if ($realPath === false || !is_readable($realPath)) {
            return false;
        }

        return $realPath;
    }

    /**
     * 扫描目录并缓存文件状态
     *
     * @param string $path
     * @param array|null $ext
     *
     * @return void
     */
    private function scanDirectory(string $path, array|null $ext = null): void
    {
        if (!is_dir($path) || !is_readable($path)) {
            return;
        }


        $this->cache[$path] = (new SplFileInfo($path))->getMTime();

        try {
            if ($ext === null) {

                $scan = scandir($path);
                if ($scan === false) {
                    return;
                }

                foreach ($scan as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }

                    $itemPath = $path . DIRECTORY_SEPARATOR . $item;
                    if (is_readable($itemPath)) {
                        if (is_file($itemPath)) {
                            $this->cache[$itemPath] = (new SplFileInfo($itemPath))->getMTime();
                        } elseif (is_dir($itemPath)) {
                            $this->scanDirectory($itemPath);
                        }
                    }
                }
            } else {

                $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS;
                try {
                    $directory = new RecursiveDirectoryIterator($path, $flags);
                    $iterator  = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);

                    foreach ($iterator as $item) {
                        if ($item->isFile() && in_array($item->getExtension(), $ext, true)) {
                            $realPath = $item->getRealPath();
                            if ($realPath !== false) {
                                $this->cache[$realPath] = $item->getMTime();
                            }
                        }
                    }
                } catch (Exception $e) {

                }
            }
        } catch (Exception $e) {

        }
    }

    /**
     * 检查文件变化
     *
     * @return void
     */
    private function tick(): void
    {

        if ($this->isInChildProcess()) {
            $this->stop();
            return;
        }

        foreach ($this->list as $path => $ext) {
            try {
                if (!file_exists($path)) {
                    continue;
                }

                if (is_file($path)) {
                    $this->checkFile($path);
                } elseif (is_dir($path)) {
                    $this->checkDirectory($path, $ext);
                }
            } catch (Exception $e) {

                continue;
            }
        }
    }

    /**
     * 检查单个文件的变化
     *
     * @param string $path
     *
     * @return void
     */
    private function checkFile(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $fileInfo = new SplFileInfo($path);
        if (!isset($this->cache[$path])) {
            $this->onEvent($fileInfo, Monitor::TOUCH);
        } elseif ($this->cache[$path] !== $fileInfo->getMTime()) {
            $this->onEvent($fileInfo, Monitor::MODIFY);
        }
    }

    /**
     * 检查目录中文件的变化
     *
     * @param string     $path
     * @param array|null $ext
     *
     * @return void
     */
    private function checkDirectory(string $path, array|null $ext): void
    {
        if (!is_dir($path) || !is_readable($path)) {
            return;
        }

        if ($ext === null) {

            $info = new SplFileInfo($path);
            if (!isset($this->cache[$path])) {
                $this->onEvent($info, Monitor::TOUCH);
            } elseif ($this->cache[$path] !== $info->getMTime()) {
                $this->onEvent($info, Monitor::MODIFY);


                $this->scanDirectory($path);
            }
        } else {

            try {
                $flags     = FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS;
                $directory = new RecursiveDirectoryIterator($path, $flags);
                $iterator  = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);

                foreach ($iterator as $item) {
                    if ($item->isFile() && in_array($item->getExtension(), $ext, true)) {
                        $realPath = $item->getRealPath();
                        if ($realPath === false) {
                            continue;
                        }

                        if (!isset($this->cache[$realPath])) {
                            $this->onEvent($item, Monitor::TOUCH);
                        } elseif ($this->cache[$realPath] !== $item->getMTime()) {
                            $this->onEvent($item, Monitor::MODIFY);
                        }
                    }
                }
            } catch (Exception $e) {

            }
        }
    }

    /**
     * 触发事件回调
     *
     * @param SplFileInfo $fileInfo
     * @param string      $event
     *
     * @return void
     */
    private function onEvent(SplFileInfo $fileInfo, string $event): void
    {
        $path = $fileInfo->getRealPath();
        if ($path === false) {
            return;
        }

        switch ($event) {
            case Monitor::TOUCH:
                if ($this->onTouch instanceof Closure) {
                    ($this->onTouch)($path);
                    $this->cache[$path] = $fileInfo->getMTime();
                }
                break;

            case Monitor::MODIFY:
                if ($this->onModify instanceof Closure) {
                    ($this->onModify)($path);
                    $this->cache[$path] = $fileInfo->getMTime();
                }
                break;

            case Monitor::REMOVE:
                if ($this->onRemove instanceof Closure) {
                    ($this->onRemove)($path);
                }
                break;
        }
    }

    /**
     * 检查文件删除
     *
     * @return void
     */
    private function inspector(): void
    {

        if ($this->isInChildProcess()) {
            $this->stop();
            return;
        }

        foreach ($this->cache as $path => $time) {
            if (!file_exists($path)) {
                if ($this->onRemove instanceof Closure) {
                    ($this->onRemove)($path);
                }

                unset($this->cache[$path]);

                if (isset($this->list[$path])) {
                    unset($this->list[$path]);
                }
            }
        }
    }

    /**
     * 设置检查间隔
     *
     * @param int $seconds
     *
     * @return self
     */
    public function setInterval(int $seconds): self
    {
        $this->interval = max(1, $seconds);
        return $this;
    }

    /**
     * 清除当前所有监控项
     *
     * @return self
     */
    public function clear(): self
    {
        $this->stop();
        $this->list  = [];
        $this->cache = [];
        return $this;
    }

    /**
     * 析构函数确保资源释放
     */
    public function __destruct()
    {
        $this->stop();
    }
}
