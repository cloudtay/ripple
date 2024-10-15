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

namespace Psc\Core\Coroutine;

use Closure;
use Fiber;
use FiberError;
use JetBrains\PhpStorm\NoReturn;
use Psc\Core\Coroutine\Exception\EscapeException;
use Psc\Core\Coroutine\Exception\Exception;
use Psc\Core\LibraryAbstract;
use Psc\Kernel;
use Psc\Utils\Output;
use Revolt\EventLoop;
use Symfony\Component\DependencyInjection\Container;
use Throwable;
use WeakMap;
use WeakReference;

use function Co\delay;
use function Co\forked;
use function Co\getSuspension;
use function Co\promise;
use function Co\wait;
use function time;

/**
 * 2024-07-13 principle
 *
 * async is a single-line Fiber independent of EventLoop. Operations on Fiber must take into account the coroutine space of EventLoop.
 * Any suspend/resume should be responsible for the Fiber of the current operation, including the return processing of the results
 *
 * 2024-07-13 Compatible with Process module
 */
class Coroutine extends LibraryAbstract
{
    /*** @var LibraryAbstract */
    protected static LibraryAbstract $instance;

    /*** @var WeakMap<object,WeakReference<Suspension>> */
    private WeakMap $fiber2suspension;

    /*** @var WeakMap<object,WeakReference<Container>> */
    private WeakMap $containers;

    public function __construct()
    {
        $this->registerOnFork();

        $this->containers       = new WeakMap();
        $this->fiber2suspension = new WeakMap();
    }

    /**
     * @return void
     */
    private function registerOnFork(): void
    {
        forked(function () {
            $this->fiber2suspension = new WeakMap();
            $this->registerOnFork();
        });
    }

    /**
     * @return bool
     */
    public function hasCallback(): bool
    {
        if (!$fiber = Fiber::getCurrent()) {
            return false;
        }

        if (!isset($this->fiber2suspension[$fiber])) {
            return false;
        }

        return true;
    }

    /**
     * @return EventLoop\Suspension
     */
    public function getSuspension(): EventLoop\Suspension
    {
        if (!$fiber = Fiber::getCurrent()) {
            return EventLoop::getSuspension();
        }

        return ($this->fiber2suspension[$fiber] ?? null)?->get() ?? EventLoop::getSuspension();
    }

    /**
     * @param Promise $promise
     *
     * @return mixed
     * @throws Throwable
     */
    public function await(Promise $promise): mixed
    {
        if ($promise->getStatus() === Promise::FULFILLED) {
            $result = $promise->getResult();
            if ($result instanceof Promise) {
                return $this->await($result);
            }
            return $result;
        }

        if ($promise->getStatus() === Promise::REJECTED) {
            throw $promise->getResult();
        }

        $suspension = getSuspension();
        if (!$fiber = Fiber::getCurrent()) {
            $promise->then(fn ($result) => Coroutine::resume($suspension, $result));
            $promise->except(fn (mixed $e) => $suspension->throw($e));

            $result = Coroutine::suspend($suspension);
            if ($result instanceof Promise) {
                return $this->await($result);
            }
            return $result;
        }

        if (!$suspension instanceof Suspension) {
            $promise->then(static fn ($result) => Coroutine::resume($suspension, $result));
            $promise->except(static function (mixed $e) use ($suspension) {
                $e instanceof Throwable
                    ? $suspension->throw($e)
                    : $suspension->throw(new Exception('An exception occurred in the awaited Promise'));
            });

            $result = Coroutine::suspend($suspension);
            if ($result instanceof Promise) {
                return $this->await($result);
            }
            return $result;
        }

        /**
         * To determine your own control over preparing Fiber, you must be responsible for the subsequent status of Fiber.
         */
        // When the status of the awaited Promise is completed
        $promise->then(static function (mixed $result) use ($fiber, $suspension) {
            try {
                // Try to resume Fiber operation
                Coroutine::resume($suspension, $result);

                // Fiber has been terminated
                if ($fiber->isTerminated()) {
                    try {
                        $suspension->resolve($fiber->getReturn());
                        return;
                    } catch (FiberError $e) {
                        $suspension->reject($e);
                        return;
                    }
                }
            } catch (EscapeException $exception) {
                // An escape exception occurs during recovery operation
                $this->handleEscapeException($exception);
            } catch (Throwable $e) {
                // Unexpected exception occurred during recovery operation

                $suspension->reject($e);
                return;
            }
        });

        // When rejected by the status of the awaited Promise
        $promise->except(static function (mixed $e) use ($fiber, $suspension) {
            try {
                // Try to notice Fiber: An exception occurred in the awaited Promise
                $e instanceof Throwable
                    ? $fiber->throw($e)
                    : $fiber->throw(new Exception('An exception occurred in the awaited Promise'));

                // Fiber has been terminated
                if ($fiber->isTerminated()) {
                    try {
                        $suspension->resolve($fiber->getReturn());
                        return;
                    } catch (FiberError $e) {
                        $suspension->reject($e);
                        return;
                    }
                }
            } catch (EscapeException $exception) {
                // An escape exception occurs during recovery operation
                $this->handleEscapeException($exception);
            } catch (Throwable $e) {
                // Unexpected exception occurred during recovery operation
                $suspension->reject($e);
                return;
            }
        });

        // Confirm that you have prepared to handle Fiber recovery and take over control of Fiber by suspending it
        $result = Coroutine::suspend($suspension);
        if ($result instanceof Promise) {
            return $this->await($result);
        }
        return $result;
    }

    /**
     * @param EscapeException $exception
     *
     * @return void
     * @throws EscapeException
     * @throws Throwable
     */
    #[NoReturn]
    public function handleEscapeException(EscapeException $exception): void
    {
        if (!Fiber::getCurrent() || !$this->hasCallback()) {
            $this->fiber2suspension = new WeakMap();
            wait();
            exit(0);
        } else {
            throw $exception;
        }
    }

    /**
     * @param Closure $closure
     *
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        return promise(function (Closure $resolve, Closure $reject, Promise $promise) use ($closure) {
            $suspension                                 = new Suspension($closure, $resolve, $reject, $promise);
            $this->fiber2suspension[$suspension->fiber] = WeakReference::create($suspension);

            try {
                $result = $suspension->start();
            } catch (EscapeException $exception) {
                $this->handleEscapeException($exception);
            }

            if ($suspension->fiber->isTerminated()) {
                try {
                    $resolve($result);
                    return;
                } catch (FiberError $e) {
                    $reject($e);
                    return;
                }
            }
        });
    }

    /**
     * @param int|float $second
     *
     * @return int
     * @throws Throwable
     */
    public function sleep(int|float $second): int
    {
        $startTime = time();
        if (!$fiber = Fiber::getCurrent()) {
            //is Revolt
            $suspension = getSuspension();
            delay(static fn () => Coroutine::resume($suspension, 0), $second);
            return Coroutine::suspend($suspension);

        } elseif (!$suspension = ($this->fiber2suspension[$fiber] ?? null)?->get()) {
            //is Revolt
            $suspension = getSuspension();
            delay(static fn () => Coroutine::resume($suspension, 0), $second);
            return Coroutine::suspend($suspension);

        } else {
            /*** @var Suspension $suspension */
            delay(function () use ($fiber, $suspension) {
                try {
                    // Try to resume Fiber operation
                    Coroutine::resume($suspension, 0);
                } catch (Throwable $e) {
                    // Unexpected exception occurred during recovery operation

                    $suspension->reject($e);
                    return;
                }

                if ($fiber->isTerminated()) {
                    try {
                        $suspension->resolve($fiber->getReturn());
                        return;
                    } catch (FiberError $e) {
                        $suspension->reject($e);
                        return;
                    }
                }
            }, $second);

            try {
                return Coroutine::suspend($suspension);
            } catch (Throwable $e) {
                Output::exception($e);
            }
        }

        return time() - $startTime;
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/30 10:03
     * @return Container
     */
    public function getContainer(): Container
    {
        if (!$fiber = Fiber::getCurrent()) {
            Kernel::getInstance()->getContainer();
        }

        $container = ($this->containers[$fiber] ?? null)?->get();

        if ($container) {
            return $container;
        }

        $containerOg              = new Container();
        $this->containers[$fiber] = WeakReference::create($containerOg);
        return $containerOg;
    }

    /**
     * @param \Revolt\EventLoop\Suspension $suspension
     * @param mixed|null                   $result
     *
     * @return void
     * @throws Throwable
     */
    public static function resume(EventLoop\Suspension $suspension, mixed $result = null): void
    {
        try {
            $suspension->resume($result);
        } catch (EscapeException $exception) {
            Coroutine::getInstance()->handleEscapeException($exception);
        }
    }

    /**
     * @param \Psc\Core\Coroutine\Suspension $suspension
     *
     * @return mixed
     * @throws Throwable
     */
    public static function suspend(EventLoop\Suspension $suspension): mixed
    {
        try {
            return $suspension->suspend();
        } catch (EscapeException $exception) {
            Coroutine::getInstance()->handleEscapeException($exception);
        }
    }
}
