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

use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\FiberLocal;
use Revolt\EventLoop\Internal\ClosureHelper;
use Revolt\EventLoop\Internal\DeferCallback;
use Revolt\EventLoop\Internal\DriverCallback;
use Revolt\EventLoop\Internal\DriverSuspension;
use Revolt\EventLoop\Internal\SignalCallback;
use Revolt\EventLoop\Internal\StreamCallback;
use Revolt\EventLoop\Internal\StreamReadableCallback;
use Revolt\EventLoop\Internal\StreamWritableCallback;
use Revolt\EventLoop\Internal\TimerCallback;
use Revolt\EventLoop\InvalidCallbackError;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UncaughtThrowable;

/**
 * 事件循环驱动程序实现所有基本操作以实现互操作性。
 *
 * 回调（已启用或新的回调）必须立即标记为已启用，但只能被激活（即回调可以被调用）就在下一个报价之前。回调不得在启用时调用。
 *
 * 所有注册的回调不得从启用严格类型的文件中调用（`declare(strict_types=1)`）。
 *
 * @内部的
 */
abstract class AbstractDriver implements Driver
{
    /** @var string Next callback identifier. */
    private string $nextId = "a";

    private \Fiber $fiber;

    private \Fiber  $callbackFiber;
    private Closure $errorCallback;

    /** @var array<string, DriverCallback> */
    private array $callbacks = array();

    /** @var array<string, DriverCallback> */
    private array $enableQueue = array();

    /** @var array<string, DriverCallback> */
    private array $enableDeferQueue = array();

    /** @var null|Closure(\Throwable):void */
    private ?Closure $errorHandler = null;

    /** @var null|Closure():mixed */
    private ?Closure $interrupt = null;

    private readonly Closure $interruptCallback;
    private readonly Closure $queueCallback;
    private readonly Closure $runCallback;

    private readonly \stdClass $internalSuspensionMarker;

    /** @var SplQueue<array{Closure, array}> */
    private readonly SplQueue $microtaskQueue;

    /** @var SplQueue<DriverCallback> */
    private readonly SplQueue $callbackQueue;

    /**
     * 是否在空闲状态。
     * @var bool
     */
    private bool $idle = false;
    private bool $stopped = false;

    /** @var WeakMap<object, WeakReference<DriverSuspension>> */
    private WeakMap $suspensions;

    public function __construct()
    {
        if (\PHP_VERSION_ID < 80117 || \PHP_VERSION_ID >= 80200 && \PHP_VERSION_ID < 80204) {
            // PHP GC is broken on early 8.1 and 8.2 versions, see https://github.com/php/php-src/issues/10496
            if (!\getenv('REVOLT_DRIVER_SUPPRESS_ISSUE_10496')) {
                throw new \Error('Your version of PHP is affected by serious garbage collector bugs related to fibers. Please upgrade to a newer version of PHP, i.e. >= 8.1.17 or => 8.2.4');
            }
        }

        $this->suspensions = new WeakMap();

        $this->internalSuspensionMarker = new \stdClass();
        $this->microtaskQueue = new SplQueue();
        $this->callbackQueue = new SplQueue();

        /**
         * 创建时间循环纤程, 用于执行事件循环。
         * 该纤程在事件循环期间保持活动状态，直到事件列表为空
         */
        $this->createLoopFiber();

        /**
         * 创建回调纤程，用于执行回调。
         */
        $this->createCallbackFiber();
        $this->createErrorCallback();

        /** @psalm-suppress InvalidArgument */
        /**
         * @psalm-suppress 无效的论点
         */
        $this->interruptCallback = $this->setInterrupt(...);

        /**
         * 创建队列回调，用于在事件循环中排队新的回调。
         */
        $this->queueCallback = $this->queue(...);

        /**
         * 创建运行回调，用于在事件循环中运行回调。
         */
        $this->runCallback = function () {
            if ($this->fiber->isTerminated()) {
                $this->createLoopFiber();
            }

            return $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();
        };
    }

    public function run(): void
    {
        if ($this->fiber->isRunning()) {
            throw new \Error("The event loop is already running");
        }

        if (\Fiber::getCurrent()) {
            throw new \Error(\sprintf("Can't call %s() within a fiber (i.e., outside of {main})", __METHOD__));
        }

        /**
         * 如果事件循环已经终止，则创建一个新的事件循环纤程。
         */
        if ($this->fiber->isTerminated()) {
            $this->createLoopFiber();
        }

        /** @noinspection PhpUnhandledExceptionInspection */
        $lambda = $this->fiber->isStarted() ? $this->fiber->resume() : $this->fiber->start();

        if ($lambda) {
            $lambda();

            throw new \Error('事件循环中断必须抛出异常: ' . ClosureHelper::getDescription($lambda));
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function isRunning(): bool
    {
        return $this->fiber->isRunning() || $this->fiber->isSuspended();
    }

    public function queue(Closure $closure, mixed ...$args): void
    {
        $this->microtaskQueue->enqueue([$closure, $args]);
    }

    public function defer(Closure $closure): string
    {
        $deferCallback = new DeferCallback($this->nextId++, $closure);

        $this->callbacks[$deferCallback->id] = $deferCallback;
        $this->enableDeferQueue[$deferCallback->id] = $deferCallback;

        return $deferCallback->id;
    }

    public function delay(float $delay, Closure $closure): string
    {
        if ($delay < 0) {
            throw new \Error("延迟必须大于或等于零");
        }

        $timerCallback = new TimerCallback($this->nextId++, $delay, $closure, $this->now() + $delay);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    public function repeat(float $interval, Closure $closure): string
    {
        if ($interval < 0) {
            throw new \Error("间隔必须大于或等于零");
        }

        $timerCallback = new TimerCallback($this->nextId++, $interval, $closure, $this->now() + $interval, true);

        $this->callbacks[$timerCallback->id] = $timerCallback;
        $this->enableQueue[$timerCallback->id] = $timerCallback;

        return $timerCallback->id;
    }

    public function onReadable(mixed $stream, Closure $closure): string
    {
        $streamCallback = new StreamReadableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    public function onWritable($stream, Closure $closure): string
    {
        $streamCallback = new StreamWritableCallback($this->nextId++, $closure, $stream);

        $this->callbacks[$streamCallback->id] = $streamCallback;
        $this->enableQueue[$streamCallback->id] = $streamCallback;

        return $streamCallback->id;
    }

    public function onSignal(int $signal, Closure $closure): string
    {
        $signalCallback = new SignalCallback($this->nextId++, $closure, $signal);

        $this->callbacks[$signalCallback->id] = $signalCallback;
        $this->enableQueue[$signalCallback->id] = $signalCallback;

        return $signalCallback->id;
    }

    public function enable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $callback = $this->callbacks[$callbackId];

        if ($callback->enabled) {
            return $callbackId; // Callback already enabled.
        }

        $callback->enabled = true;

        if ($callback instanceof DeferCallback) {
            $this->enableDeferQueue[$callback->id] = $callback;
        } elseif ($callback instanceof TimerCallback) {
            $callback->expiration = $this->now() + $callback->interval;
            $this->enableQueue[$callback->id] = $callback;
        } else {
            $this->enableQueue[$callback->id] = $callback;
        }

        return $callbackId;
    }

    public function cancel(string $callbackId): void
    {
        $this->disable($callbackId);
        unset($this->callbacks[$callbackId]);
    }

    public function disable(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $callback = $this->callbacks[$callbackId];

        if (!$callback->enabled) {
            return $callbackId; // 回调已禁用。
        }

        $callback->enabled = false;
        $callback->invokable = false;
        $id = $callback->id;

        if ($callback instanceof DeferCallback) {
            // 回调仅排队等待启用。
            unset($this->enableDeferQueue[$id]);
        } elseif (isset($this->enableQueue[$id])) {
            // 回调仅排队等待启用。
            unset($this->enableQueue[$id]);
        } else {
            $this->deactivate($callback);
        }

        return $callbackId;
    }

    public function reference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            throw InvalidCallbackError::invalidIdentifier($callbackId);
        }

        $this->callbacks[$callbackId]->referenced = true;

        return $callbackId;
    }

    public function unreference(string $callbackId): string
    {
        if (!isset($this->callbacks[$callbackId])) {
            return $callbackId;
        }

        $this->callbacks[$callbackId]->referenced = false;

        return $callbackId;
    }

    public function getSuspension(): Suspension
    {
        $fiber = \Fiber::getCurrent();

        // 用户回调始终在事件循环纤程之外执行，因此这应该始终为 false。
        \assert($fiber !== $this->fiber);

        // 在 {main} 的情况下使用队列关闭，在未捕获的异常后可以通过 DriverSuspension 取消设置。
        $key = $fiber ?? $this->queueCallback;

        $suspension = ($this->suspensions[$key] ?? null)?->get();
        if ($suspension) {
            return $suspension;
        }

        $suspension = new DriverSuspension(
            $this->runCallback,
            $this->queueCallback,
            $this->interruptCallback,
            $this->suspensions,
        );

        $this->suspensions[$key] = WeakReference::create($suspension);

        return $suspension;
    }

    public function setErrorHandler(?Closure $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    public function getErrorHandler(): ?Closure
    {
        return $this->errorHandler;
    }

    public function __debugInfo(): array
    {
        // @代码覆盖率忽略开始
        return \array_map(fn (DriverCallback $callback) => [
            'type' => $this->getType($callback->id),
            'enabled' => $callback->enabled,
            'referenced' => $callback->referenced,
        ], $this->callbacks);
        // @代码覆盖率忽略结束
    }

    public function getIdentifiers(): array
    {
        return \array_keys($this->callbacks);
    }

    public function getType(string $callbackId): CallbackType
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return match ($callback::class) {
            DeferCallback::class => CallbackType::Defer,
            TimerCallback::class => $callback->repeat ? CallbackType::Repeat : CallbackType::Delay,
            StreamReadableCallback::class => CallbackType::Readable,
            StreamWritableCallback::class => CallbackType::Writable,
            SignalCallback::class => CallbackType::Signal,
        };
    }

    public function isEnabled(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->enabled;
    }

    public function isReferenced(string $callbackId): bool
    {
        $callback = $this->callbacks[$callbackId] ?? throw InvalidCallbackError::invalidIdentifier($callbackId);

        return $callback->referenced;
    }

    /**
     * 激活（启用）所有给定的回调。
     */
    abstract protected function activate(array $callbacks): void;

    /**
     * 调度任何挂起的读/写、计时器和信号事件。
     */
    abstract protected function dispatch(bool $blocking): void;

    /**
     * 停用（禁用）给定的回调。
     */
    abstract protected function deactivate(DriverCallback $callback): void;

    final protected function enqueueCallback(DriverCallback $callback): void
    {
        $this->callbackQueue->enqueue($callback);
        $this->idle = false;
    }

    /**
     * 使用给定的异常调用错误处理程序。
     *
     * @param \Throwable $exception 事件回调抛出的异常。
     */
    final protected function error(Closure $closure, \Throwable $exception): void
    {
        if ($this->errorHandler === null) {
            // Explicitly override the previous interrupt if it exists in this case, hiding the exception is worse
            $this->interrupt = static fn () => $exception instanceof UncaughtThrowable
                ? throw $exception
                : throw UncaughtThrowable::throwingCallback($closure, $exception);
            return;
        }

        $fiber = new \Fiber($this->errorCallback);

        /** @noinspection PhpUnhandledExceptionInspection */
        $fiber->start($this->errorHandler, $exception);
    }

    /**
     * 以秒为增量返回当前事件循环时间。
     *
     * 请注意，该值不一定与挂钟时间相关，而是要使用返回的值
     * 与此方法返回的先前值的相对比较（间隔、到期计算等）。
     */
    abstract protected function now(): float;

    private function invokeMicrotasks(): void
    {
        while (!$this->microtaskQueue->isEmpty()) {
            [$callback, $args] = $this->microtaskQueue->dequeue();

            try {
                // Clear $args to allow garbage collection
                $callback(...$args, ...($args = []));
            } catch (\Throwable $exception) {
                $this->error($callback, $exception);
            } finally {
                FiberLocal::clear();
            }

            unset($callback, $args);

            if ($this->interrupt) {
                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            }
        }
    }

    /**
     * @return bool 如果循环中没有启用和引用的回调，则为 True。
     */
    private function isEmpty(): bool
    {
        foreach ($this->callbacks as $callback) {
            if ($callback->enabled && $callback->referenced) {
                return false;
            }
        }

        return true;
    }

    /**
     * 执行事件循环的单个tick。
     */
    private function tick(bool $previousIdle): void
    {
        $this->activate($this->enableQueue);

        foreach ($this->enableQueue as $callback) {
            $callback->invokable = true;
        }

        $this->enableQueue = array();

        foreach ($this->enableDeferQueue as $callback) {
            $callback->invokable = true;
            $this->enqueueCallback($callback);
        }

        $this->enableDeferQueue = array();

        $blocking = $previousIdle
                    && !$this->stopped
                    && !$this->isEmpty();

        if ($blocking) {
            $this->invokeCallbacks();

            /** @psalm-suppress TypeDoesNotContainType */
            if (!empty($this->enableDeferQueue) || !empty($this->enableQueue)) {
                $blocking = false;
            }
        }

        /** @psalm-suppress RedundantCondition */
        $this->dispatch($blocking);
    }

    private function invokeCallbacks(): void
    {
        while (!$this->microtaskQueue->isEmpty() || !$this->callbackQueue->isEmpty()) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $yielded = $this->callbackFiber->isStarted()
                ? $this->callbackFiber->resume()
                : $this->callbackFiber->start();

            if ($yielded !== $this->internalSuspensionMarker) {
                $this->createCallbackFiber();
            }

            if ($this->interrupt) {
                $this->invokeInterrupt();
            }
        }
    }

    /**
     * @param Closure():mixed $interrupt
     */
    private function setInterrupt(Closure $interrupt): void
    {
        \assert($this->interrupt === null);

        $this->interrupt = $interrupt;
    }

    private function invokeInterrupt(): void
    {
        \assert($this->interrupt !== null);

        $interrupt = $this->interrupt;
        $this->interrupt = null;

        /** @noinspection PhpUnhandledExceptionInspection */
        \Fiber::suspend($interrupt);
    }

    private function createLoopFiber(): void
    {
        $this->fiber = new \Fiber(function (): void {
            $this->stopped = false;

            // 如果我们有一些微任务，我们需要在第一个tick之前执行它们。
            $this->invokeCallbacks();

            /** @psalm-suppress RedundantCondition $this->stopped 可以通过 $this->invokeCallbacks() 更改. */
            while (!$this->stopped) {
                if ($this->interrupt) {
                    $this->invokeInterrupt();
                }

                if ($this->isEmpty()) {
                    return;
                }

                $previousIdle = $this->idle;
                $this->idle = true;

                $this->tick($previousIdle);
                $this->invokeCallbacks();
            }
        });
    }

    private function createCallbackFiber(): void
    {
        $this->callbackFiber = new \Fiber(function (): void {
            do {
                $this->invokeMicrotasks();

                while (!$this->callbackQueue->isEmpty()) {
                    /** @var DriverCallback $callback */
                    $callback = $this->callbackQueue->dequeue();

                    if (!isset($this->callbacks[$callback->id]) || !$callback->invokable) {
                        unset($callback);

                        continue;
                    }

                    if ($callback instanceof DeferCallback) {
                        $this->cancel($callback->id);
                    } elseif ($callback instanceof TimerCallback) {
                        if (!$callback->repeat) {
                            $this->cancel($callback->id);
                        } else {
                            // 禁用并重新启用，因此不会在同一个tick中重复执行
                            // 请参阅 https://github.com/amphp/amp/issues/131
                            $this->disable($callback->id);
                            $this->enable($callback->id);
                        }
                    }

                    try {
                        $result = match (true) {
                            $callback instanceof StreamCallback => ($callback->closure)(
                                $callback->id,
                                $callback->stream
                            ),
                            $callback instanceof SignalCallback => ($callback->closure)(
                                $callback->id,
                                $callback->signal
                            ),
                            default => ($callback->closure)($callback->id),
                        };

                        if ($result !== null) {
                            throw InvalidCallbackError::nonNullReturn($callback->id, $callback->closure);
                        }
                    } catch (\Throwable $exception) {
                        $this->error($callback->closure, $exception);
                    } finally {
                        FiberLocal::clear();
                    }

                    unset($callback);

                    if ($this->interrupt) {
                        /** @noinspection PhpUnhandledExceptionInspection */
                        \Fiber::suspend($this->internalSuspensionMarker);
                    }

                    $this->invokeMicrotasks();
                }

                /** @noinspection PhpUnhandledExceptionInspection */
                \Fiber::suspend($this->internalSuspensionMarker);
            } while (true);
        });
    }

    private function createErrorCallback(): void
    {
        $this->errorCallback = function (Closure $errorHandler, \Throwable $exception): void {
            try {
                $errorHandler($exception);
            } catch (\Throwable $exception) {
                $this->interrupt = static fn () => $exception instanceof UncaughtThrowable
                    ? throw $exception
                    : throw UncaughtThrowable::throwingErrorHandler($errorHandler, $exception);
            }
        };
    }
}
