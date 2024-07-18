<?php

declare(strict_types=1);

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
    private array $cache = [];

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

        $this->cache = [];
    }
}
