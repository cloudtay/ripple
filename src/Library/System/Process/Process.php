<?php

declare(strict_types=1);
/*
 * Copyright (c) 2024.
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

namespace Psc\Library\System\Process;

use Closure;
use JetBrains\PhpStorm\NoReturn;
use Psc\Core\Output;
use Psc\Core\StoreAbstract;
use Psc\Library\IO\FIle\File;
use Psc\Library\System\Exception\ProcessException;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

use function array_pop;
use function call_user_func;
use function P\cancel;
use function P\onSignal;
use function P\promise;
use function P\repeat;
use function pcntl_fork;
use function pcntl_wait;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function posix_getpid;
use function posix_getppid;

use const SIGCHLD;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;
use const WNOHANG;
use const WUNTRACED;

/**
 *
 */
class Process extends StoreAbstract
{
    /**
     * @var StoreAbstract
     */
    protected static StoreAbstract $instance;

    /**
     * @var array
     */
    private array $process2promiseCallback = [];

    /**
     * @var Runtime[]
     */
    private array $process2runtime = [];

    /**
     * @var array
     */
    private array $onFork = [];

    /**
     * @throws EventLoop\UnsupportedFeatureException
     */
    public function __construct()
    {
        $this->registerSignalHandler();
    }

    /**
     */
    private function registerSignalHandler(): void
    {
        pcntl_async_signals(true);

        onSignal(SIGCHLD, function () {
            $this->signalSIGCHLDHandler();
        });

        onSignal(SIGTERM, function () {
            $this->onQuitSignal(SIGTERM);
        });

        onSignal(SIGINT, function () {
            $this->onQuitSignal(SIGINT);
        });

        onSignal(SIGQUIT, function () {
            $this->onQuitSignal(SIGQUIT);
        });

        repeat(function () {
            foreach ($this->process2runtime as $key => $p) {
                if ($childrenId = pcntl_waitpid($key, $status, WNOHANG)) {
                    $this->onProcessExit($childrenId, $status);
                }
            }

            pcntl_signal_dispatch();
        }, 1);
    }


    /**
     * @return void
     */
    private function signalSIGCHLDHandler(): void
    {
        while (1) {
            $childrenId = pcntl_wait(
                $status,
                WNOHANG | WUNTRACED
            );

            if ($childrenId <= 0) {
                break;
            }

            $this->onProcessExit($childrenId, $status);
        }
    }

    /**
     * @param int $processId
     * @param int $status
     * @return void
     */
    private function onProcessExit(int $processId, int $status): void
    {
        $exit            = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1;
        $promiseCallback = $this->process2promiseCallback[$processId] ?? null;
        if (!$promiseCallback) {
            return;
        }

        if ($exit === -1) {
            call_user_func($promiseCallback['reject'], new ProcessException('The process is abnormal.', $exit));
        } else {
            call_user_func($promiseCallback['resolve'], $exit);
        }

        unset($this->process2promiseCallback[$processId]);
        unset($this->process2runtime[$processId]);
    }

    /**
     * @param $signal
     * @return void
     */
    #[NoReturn] public function onQuitSignal($signal): void
    {
        $this->destroy($signal);
        exit;
    }

    /**
     * @param int $signal
     * @return void
     */
    private function destroy(int $signal = SIGTERM): void
    {
        foreach ($this->process2runtime as $runtime) {
            $runtime->signal($signal);
        }
    }

    /**
     * @param Closure $closure
     * @return void
     */
    public function onFork(Closure $closure): void
    {
        $this->onFork[] = $closure;
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException
     */
    public function noticeFork(): void
    {
        $this->resetDriver();

        $this->registerSignalHandler();

        File::getInstance()->noticeFork();

        while ($closure = array_pop($this->onFork)) {
            try {
                $closure();
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }

        $this->process2promiseCallback = [];
        $this->process2runtime         = [];
    }

    /**
     * @return void
     */
    public function resetDriver(): void
    {
        foreach (EventLoop::getIdentifiers() as $identifier) {
            cancel($identifier);
        }
    }

    /**
     * @return int
     * @throws ProcessException|UnsupportedFeatureException
     */
    public function fork(): int
    {
        $processId = pcntl_fork();
        if ($processId === -1) {
            throw new ProcessException('Fork failed.');
        }
        if ($processId === 0) {
            $this->noticeFork();
        }
        return $processId;
    }

    /**
     * @return int
     */
    public function getPid(): int
    {
        return posix_getpid();
    }

    /**
     * @return int
     */
    public function getPPid(): int
    {
        return posix_getppid();
    }

    /**
     * @param Closure $closure
     * @return Task
     */
    public function task(Closure $closure): Task
    {
        return new Task(function (...$args) use ($closure) {
            $processId = $this->fork();

            if ($processId === 0) {
                call_user_func($closure, ...$args);
                EventLoop::getSuspension()->suspend();
            }

            $promise = promise(function ($r, $d) use ($processId) {
                $this->process2promiseCallback[$processId] = [
                    'resolve' => $r,
                    'reject'  => $d,
                ];
            });

            $runtime = new Runtime(
                $promise,
                $processId,
            );

            $this->process2runtime[$processId] = $runtime;
            return $runtime;
        });
    }
}