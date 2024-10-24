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

namespace Ripple\Coroutine;

use Closure;
use Fiber;
use FiberError;
use JetBrains\PhpStorm\NoReturn;
use Revolt\EventLoop;
use Ripple\Coroutine\Exception\EscapeException;
use Ripple\Coroutine\Exception\PromiseRejectException;
use Ripple\Kernel;
use Ripple\LibraryAbstract;
use Symfony\Component\DependencyInjection\Container;
use Throwable;
use WeakMap;
use WeakReference;

use function Co\delay;
use function Co\forked;
use function Co\getSuspension;
use function Co\promise;
use function Co\wait;

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
     * This method is different from onReject, which allows accepting any type of rejected futures object.
     * When await promise is rejected, an error will be thrown instead of returning the rejected value.
     *
     * If the rejected value is a non-Error object, it will be wrapped into a `PromiseRejectException` object,
     * The `getReason` method of this object can obtain the rejected value
     *
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
            if ($promise->getResult() instanceof Throwable) {
                throw $promise->getResult();
            } else {
                throw new PromiseRejectException($promise->getResult());
            }
        }

        $suspension = getSuspension();
        if (!$suspension instanceof Suspension) {
            $promise->then(static fn ($result) => Coroutine::resume($suspension, $result));
            $promise->except(static function (mixed $result) use ($suspension) {
                $result instanceof Throwable
                    ? Coroutine::throw($suspension, $result)
                    : Coroutine::throw($suspension, new PromiseRejectException($result));
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
        $promise
            ->then(static fn (mixed $result) => Coroutine::resume($suspension, $result))
            ->except(static function (mixed $result) use ($suspension) {
                $result instanceof Throwable
                    ? Coroutine::throw($suspension, $result)
                    : Coroutine::throw($suspension, new PromiseRejectException($result));
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
                if ($suspension->fiber->isTerminated()) {
                    try {
                        $resolve($result);
                        return;
                    } catch (FiberError $e) {
                        $reject($e);
                        return;
                    }
                }
            } catch (EscapeException $exception) {
                $this->handleEscapeException($exception);
            } catch (Throwable $exception) {
                $suspension->reject($exception);
                return;
            }
        });
    }

    /**
     * @param int|float $second
     *
     * @return int|float
     * @throws Throwable
     */
    public function sleep(int|float $second): int|float
    {
        $suspension = getSuspension();
        delay(static fn () => Coroutine::resume($suspension, $second), $second);
        return Coroutine::suspend($suspension);
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
     *
     * The coroutine that cannot be restored can only throw an exception.
     * If it is a ripple type exception, it will be caught and the contract will be rejected.
     *
     * This method attempts to resume a suspended coroutine and take over the coroutine context.
     * When the recovery fails or an exception occurs within the coroutine, an exception will be thrown.
     * This method will not return any value yet
     *
     * @param \Revolt\EventLoop\Suspension $suspension
     * @param mixed|null                   $result
     *
     * @return mixed
     * @throws Throwable
     */
    public static function resume(EventLoop\Suspension $suspension, mixed $result = null): mixed
    {
        try {
            $suspension->resume($result);
            if ($suspension instanceof Suspension && $suspension->fiber->isTerminated()) {
                try {
                    $suspension->resolve($suspension->fiber->getReturn());
                    return null;
                } catch (FiberError $e) {
                    $suspension->reject($e);
                    return null;
                }
            }
        } catch (EscapeException $exception) {
            Coroutine::getInstance()->handleEscapeException($exception);
        } catch (FiberError $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $suspension instanceof Suspension && $suspension->reject($exception);
            throw $exception;
        }

        return null;
    }

    /**
     * @param \Revolt\EventLoop\Suspension $suspension
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
        } catch (Throwable $exception) {
            $suspension instanceof Suspension && $suspension->reject($exception);
            throw $exception;
        }
    }

    /**
     * @param \Revolt\EventLoop\Suspension $suspension
     * @param Throwable                    $throwable
     *
     * @return void
     */
    public static function throw(EventLoop\Suspension $suspension, Throwable $throwable): void
    {
        $suspension->throw($throwable);
    }
}
