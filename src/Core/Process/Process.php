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

namespace Psc\Core\Process;

use Closure;
use Co\Coroutine;
use Fiber;
use Psc\Core\Coroutine\EscapeException;
use Psc\Core\LibraryAbstract;
use Psc\Core\Process\Exception\ProcessException;
use Psc\Utils\Output;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

use function call_user_func;
use function Co\cancel;
use function Co\promise;
use function Co\tick;
use function getmypid;
use function pcntl_fork;
use function pcntl_wait;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function posix_getpid;

use const SIGCHLD;
use const SIGKILL;
use const WNOHANG;
use const WUNTRACED;
use const PHP_OS_FAMILY;

/**
 * @compatible:Windows
 * 20240830对Windows支持进行了调整
 *
 * @Author cclilshy
 * @Date   2024/8/16 09:36
 */
class Process extends LibraryAbstract
{
    /*** @var LibraryAbstract */
    protected static LibraryAbstract $instance;

    /*** @var array */
    private array $process2promiseCallback = array();

    /*** @var Runtime[] */
    private array $process2runtime = array();

    /*** @var array */
    private array $onFork = array();

    /*** @var int */
    private int $rootProcessId;

    /*** @var int */
    private int $processId;

    /*** @var string */
    private string $signalHandlerEventId;

    public function __construct()
    {
        /**
         * @compatible:Windows
         * Windows 不支持pcntl扩展
         */
        if (PHP_OS_FAMILY === 'Windows') {
            $this->rootProcessId = getmypid();
            $this->processId     = getmypid();
            return;
        }

        $this->rootProcessId = posix_getpid();
        $this->processId     = posix_getpid();
    }


    public function __destruct()
    {
        $this->destroy();
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException
     */
    private function registerSignalHandler(): void
    {
        /**
         * @compatible:Windows
         * Windows 不注册信号处理器
         */
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $this->signalHandlerEventId = $this->onSignal(SIGCHLD, fn () => $this->signalSIGCHLDHandler());
    }

    /**
     * @return void
     */
    private function unregisterSignalHandler(): void
    {
        cancel($this->signalHandlerEventId);
    }

    /**
     * @return void
     */
    private function signalSIGCHLDHandler(): void
    {
        while (1) {
            $childrenId = pcntl_wait($status, WNOHANG | WUNTRACED);

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

        if (empty($this->process2runtime)) {
            $this->unregisterSignalHandler();
        }
    }

    /**
     * @return void
     */
    private function destroy(): void
    {
        foreach ($this->process2runtime as $runtime) {
            $runtime->signal(SIGKILL);
        }
    }

    /*** @var int */
    private int $index = 0;

    /**
     * @param Closure $closure
     * @return int
     */
    public function registerForkHandler(Closure $closure): int
    {
        $this->onFork[$this->index] = $closure;
        return $this->index++;
    }

    /**
     * @param int $index
     * @return void
     */
    public function cancelForkHandler(int $index): void
    {
        unset($this->onFork[$index]);
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException|Throwable
     */
    public function forked(): void
    {
        if (!empty($this->process2runtime)) {
            $this->unregisterSignalHandler();
        }

        foreach ($this->onFork as $key => $closure) {
            try {
                unset($this->onFork[$key]);
                $closure();
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }

        $this->process2promiseCallback = array();
        $this->process2runtime         = array();
        $this->processId               = posix_getpid();
    }

    /**
     * @param Closure $closure
     * @return Task|false
     */
    public function task(Closure $closure): Task|false
    {
        return new Task(function (...$args) use ($closure) {
            /**
             * @compatible:Windows
             * windows 不支持pcntl扩展
             *
             * Windows允许使用Process模块模拟一个Runtime
             * 但Runtime并非真正的子进程
             *
             * 由于__destruct与Fiber生命周期的原因
             * Windows下的Runtime一旦被销毁,会导致整个进程退出, 并且不会触发任何promise回调
             */
            if (PHP_OS_FAMILY === 'Windows') {
                call_user_func($closure, ...$args);
                return new Runtime(promise(static function () {}), getmypid());
            }

            $processId = pcntl_fork();

            if ($processId === -1) {
                Output::warning('Fork failed.');
                return false;
            }

            if ($processId === 0) {
                /**
                 * It is necessary to ensure that the final closure cannot be escaped by any means.
                 */
                foreach (EventLoop::getIdentifiers() as $identifier) {
                    try {
                        EventLoop::cancel($identifier);
                    } catch (Throwable $e) {
                        Output::error($e->getMessage());
                    }
                }

                if (Coroutine::Coroutine()->isCoroutine()) {
                    // Whether it belongs to the PRipple coroutine space
                    // forked and user actions need to be deferred because they clear the coroutine hash table
                    // If you don't do this, fiber escape will occur

                    EventLoop::defer(function () use ($closure, $args) {
                        $this->forked();
                        call_user_func($closure, ...$args);
                    });

                    throw new EscapeException('The process is abnormal.');
                } elseif (Fiber::getCurrent()) {
                    // Whether it belongs to the PHP space

                    $this->forked();
                    call_user_func($closure, ...$args);
                    tick();
                    exit(0);
                } else {
                    // Whether it belongs to the PHP space

                    $this->forked();
                    call_user_func($closure, ...$args);
                    tick();
                    exit(0);
                }
            }

            if (empty($this->process2runtime)) {
                $this->registerSignalHandler();
            }

            $promise = promise(function ($r, $d) use ($processId) {
                $this->process2promiseCallback[$processId] = array(
                    'resolve' => $r,
                    'reject'  => $d,
                );
            });

            $runtime = new Runtime(
                $promise,
                $processId,
            );

            $this->process2runtime[$processId] = $runtime;
            return $runtime;
        });
    }

    /**
     * @param int     $signalCode
     * @param Closure $handler
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signalCode, Closure $handler): string
    {
        return EventLoop::onSignal($signalCode, $handler);
    }

    /**
     * @return int
     */
    public function getRootProcessId(): int
    {
        return $this->rootProcessId;
    }
}
