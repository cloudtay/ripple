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

namespace Psc\Library\Coroutine;

use Closure;
use Fiber;
use FiberError;
use Psc\Core\Coroutine\Promise;
use Psc\Core\LibraryAbstract;
use Psc\Kernel;
use Revolt\EventLoop;
use Throwable;

use function P\delay;
use function P\registerForkHandler;
use function P\run;
use function spl_object_hash;

/**
 * 原则性
 * 2024-07-13
 * async独立于EventLoop之外的单线Fiber, 对Fiber的操作必须考虑到EventLoop的协程空间
 * 任何suspend/resume都应该对当前操作的Fiber负责, 包括结果的返回处理
 */

/**
 * 兼容性:Process模块
 * 2024-07-13
 */
class Coroutine extends LibraryAbstract
{
    /**
     * @var LibraryAbstract
     */
    protected static LibraryAbstract $instance;

    /**
     * @param Promise $promise
     * @return mixed
     * @throws Throwable
     */
    public function await(Promise $promise): mixed
    {
        if ($promise->getStatus() === Promise::FULFILLED) {
            return $promise->getResult();
        }

        if ($promise->getStatus() === Promise::REJECTED) {
            throw $promise->getResult();
        }

        if(!$fiber = Fiber::getCurrent()) {
            $suspend = EventLoop::getSuspension();
            $promise->then(fn ($result) => $suspend->resume($result));
            $promise->except(fn (mixed $e) => $suspend->resume($e));

            try {
                return $suspend->suspend();
            } catch (Throwable) {
                return false;
            }
        }

        if(!$callback = $this->fiber2callback[spl_object_hash($fiber)] ?? null) {
            $promise->then(fn ($result) => $fiber->resume($result));
            $promise->except(fn (Throwable $e) => $fiber->throw($e));

            return $fiber->suspend();
        }

        /**
         * To determine your own control over preparing Fiber, you must be responsible for the subsequent status of Fiber.
         */
        // When the status of the awaited Promise is completed
        $promise->then(function (mixed $result) use ($fiber, $callback) {
            try {
                // Try to resume Fiber operation
                $fiber->resume($result);

                // Fiber has been terminated
                if($fiber->isTerminated()) {
                    try {
                        $callback['resolve']($fiber->getReturn());
                        return;
                    } catch (FiberError $e) {
                        $callback['reject']($e);
                        return;
                    }
                }
            } catch (EscapeException $exception) {
                // An escape exception occurs during recovery operation
                $this->handleEscapeException($exception);
            } catch (Throwable $e) {
                // Unexpected exception occurred during recovery operation

                $callback['reject']($e);
                return;
            }
        });

        // When rejected by the status of the awaited Promise
        $promise->except(function (Throwable $e) use ($fiber, $callback) {
            try {
                // Try to notice Fiber: An exception occurred in the awaited Promise
                $fiber->throw($e);

                // Fiber has been terminated
                if($fiber->isTerminated()) {
                    try {
                        $callback['resolve']($fiber->getReturn());
                        return;
                    } catch (FiberError $e) {
                        $callback['reject']($e);
                        return;
                    }
                }
            } catch (EscapeException $exception) {
                // An escape exception occurs during recovery operation
                $this->handleEscapeException($exception);
            } catch (Throwable $e) {
                // Unexpected exception occurred during recovery operation
                $callback['reject']($e);
                return;
            }
        });

        // Confirm that you have prepared to handle Fiber recovery and take over control of Fiber by suspending it
        return $fiber->suspend();
    }

    /**
     * @var array $fiber2promise
     */
    private array $fiber2callback = array();

    /**
     * @param Closure $closure
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        return new Promise(function (Closure $r, Closure $d, Promise $promise) use ($closure) {
            $fiber = new Fiber($closure);
            $hash = spl_object_hash($fiber);

            $this->fiber2callback[$hash] = array(
                'resolve' => $r,
                'reject' => $d,
                'promise' => $promise,
                'fiber' => $fiber,
            );

            try {
                $fiber->start($r, $d);
            } catch (EscapeException $exception) {
                $this->handleEscapeException($exception);
            }


            if($fiber->isTerminated()) {
                try {
                    $result = $fiber->getReturn();
                    $r($result);
                    return;
                } catch (FiberError $e) {
                    $d($e);
                    return;
                }
            }

            $promise->finally(function () use ($fiber) {
                unset($this->fiber2callback[spl_object_hash($fiber)]);
            });
        });
    }

    /**
     * @return void
     */
    private function registerOnFork(): void
    {
        registerForkHandler(function () {
            $this->fiber2callback = array();

            $this->registerOnFork();
        });
    }

    /**
     *
     */
    public function __construct()
    {
        $this->registerOnFork();
    }

    /**
     * @param float|int $second
     * @return void
     * @throws Throwable
     */
    public function sleep(float|int $second): void
    {
        if(!$fiber = Fiber::getCurrent()) {
            //is Revolt
            $suspension = EventLoop::getSuspension();
            Kernel::getInstance()->delay(fn () => $suspension->resume(), $second);
            $suspension->suspend();

        } elseif(!$callback = $this->fiber2callback[spl_object_hash($fiber)] ?? null) {
            //is Revolt
            $suspension = EventLoop::getSuspension();
            Kernel::getInstance()->delay(fn () => $suspension->resume(), $second);
            $suspension->suspend();

        } else {
            delay(function () use ($fiber, $callback) {
                try {
                    // 尝试恢复Fiber运行
                    $fiber->resume();
                } catch (EscapeException) {
                    // 恢复运行过程发生逃逸异常
                    $this->handleEscapeException($exception);
                } catch (Throwable $e) {
                    // 恢复运行过程发生意料之外的异常

                    $callback['reject']($e);
                    return;
                }

                if($fiber->isTerminated()) {
                    try {
                        $result = $fiber->getReturn();
                        $callback['resolve']($result);
                        return;
                    } catch (FiberError $e) {
                        $callback['reject']($e);
                        return;
                    }
                }
            }, $second);

            $fiber->suspend();
        }
    }

    /**
     * @return bool
     */
    public function isCoroutine(): bool
    {
        if(!$fiber = Fiber::getCurrent()) {
            return false;
        }

        if(!isset($this->fiber2callback[spl_object_hash($fiber)])) {
            return false;
        }

        return true;
    }

    /**
     * @return array|null
     */
    public function getCoroutine(): array|null
    {
        if(!$fiber = Fiber::getCurrent()) {
            return null;
        }

        return $this->fiber2callback[spl_object_hash($fiber)] ?? null;
    }

    /**
     * @param EscapeException $exception
     * @return void
     * @throws EscapeException
     * @throws Throwable
     */
    public function handleEscapeException(EscapeException $exception): void
    {
        if (!Fiber::getCurrent()) {
            $this->fiber2callback = array();

            run();
            exit(0);
        }

        if ($this->isCoroutine()) {
            throw $exception;
        } else {
            $this->fiber2callback = array();

            Fiber::suspend();
        }
    }
}
