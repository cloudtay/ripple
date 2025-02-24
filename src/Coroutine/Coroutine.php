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

namespace Ripple\Coroutine;

use Closure;
use Fiber;
use JetBrains\PhpStorm\NoReturn;
use Revolt\EventLoop;
use Ripple\Coroutine\Events\EndEvent;
use Ripple\Coroutine\Events\ErrorEvent;
use Ripple\Coroutine\Events\ResumeEvent;
use Ripple\Coroutine\Events\StartEvent;
use Ripple\Coroutine\Events\SuspendEvent;
use Ripple\Coroutine\Events\TerminateEvent;
use Ripple\Coroutine\Exception\EscapeException;
use Ripple\Coroutine\Exception\PromiseRejectException;
use Ripple\Coroutine\Exception\TerminateException;
use Ripple\Event\Event;
use Ripple\Process\Process;
use Ripple\Promise;
use Ripple\Support;
use Ripple\Utils\Output;
use Throwable;
use WeakReference;
use WeakMap;
use Ripple\Event\EventDispatcher;
use Ripple\Event\EventTracer;

use function Co\delay;
use function Co\forked;
use function Co\getContext;
use function array_shift;
use function Co\go;
use function gc_collect_cycles;

class Coroutine extends Support
{
    /*** @var Support */
    protected static Support $instance;

    /*** @var WeakMap<object,WeakReference<Context>> */
    private WeakMap $fiber2context;

    /*** @var \Ripple\Event\EventDispatcher */
    private EventDispatcher $dispatcher;

    /*** @var \Ripple\Event\EventTracer */
    private EventTracer $tracer;

    /**
     *
     */
    public function __construct()
    {
        $this->registerOnFork();
        $this->fiber2context = new WeakMap();
        $this->dispatcher    = EventDispatcher::getInstance();
        $this->tracer        = EventTracer::getInstance();
    }

    /**
     * @return void
     */
    private function registerOnFork(): void
    {
        forked(function () {
            $this->fiber2context = new WeakMap();
            $this->registerOnFork();
        });
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
     * @param Context    $context
     * @param mixed|null $result
     *
     * @return mixed
     */
    public static function resume(Context $context, mixed $result = null): mixed
    {
        try {
            $context->resume($result);
        } catch (EscapeException $exception) {
            Coroutine::getInstance()->handleEscapeException($exception);
        } catch (Throwable $exception) {
            Output::warning($exception->getMessage());
        }

        return null;
    }

    /**
     * @param Context|null $context
     *
     * @return mixed
     * @throws Throwable
     */
    public static function suspend(Context|null $context = null): mixed
    {
        if (!$context) {
            $context = getContext();
        }

        try {
            Coroutine::getInstance()->dispatchEvent(new SuspendEvent());
            $result = $context->suspend();
            Coroutine::getInstance()->dispatchEvent(new ResumeEvent());
        } catch (EscapeException $exception) {
            Coroutine::getInstance()->handleEscapeException($exception);
        } finally {
            gc_collect_cycles();

            while ($event = array_shift($context->eventQueue)) {
                Coroutine::getInstance()->dispatchEvent($event);
            }
        }

        return $result;
    }

    /**
     * @param Context   $context
     * @param Throwable $exception
     *
     * @return void
     */
    public static function throw(Context $context, Throwable $exception): void
    {
        try {
            $context->throw($exception);
        } catch (Throwable) {
        }
    }

    /**
     * @param Closure $closure
     *
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        return new Promise(static function (Closure $resolve, Closure $reject) use ($closure) {
            go(static function () use ($resolve, $reject, $closure) {
                try {
                    $result = $closure();
                    $resolve($result);
                } catch (EscapeException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $reject($exception);
                }
            });
        });
    }

    /**
     * @param Closure $closure
     *
     * @return \Ripple\Coroutine\Context
     */
    public function go(Closure $closure): Context
    {
        $context       = null;
        $parentContext = getContext();
        $context       = new Context(function () use ($closure, $parentContext, &$context) {
            Context::extend($parentContext);

            try {
                $fiber = Fiber::getCurrent();
                $this->dispatchEvent(new StartEvent($fiber));

                $result = $closure();

                $this->dispatchEvent(new EndEvent($fiber, $result));
            } catch (EscapeException $exception) {
                $fiber = Fiber::getCurrent();

                $this->dispatchEvent(new ErrorEvent($fiber, $exception));
                throw $exception;
            } catch (Throwable $exception) {
                $fiber = Fiber::getCurrent();

                $this->dispatchEvent(new ErrorEvent($fiber, $exception));
            } finally {
                Context::clear();
                $this->tracer->clear($context);

                $this->processDefers($context);
            }
        });

        $this->fiber2context[$context->fiber] = WeakReference::create($context);

        try {
            $context->start();
        } catch (EscapeException $exception) {
            $this->handleEscapeException($exception);
        } catch (Throwable $exception) {
            // 有预期之外的异常泄漏
            Output::exception($exception);
        }
        return $context;
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

        $context = getContext();

        /**
         * To determine your own control over preparing Fiber, you must be responsible for the subsequent status of Fiber.
         */
        // When the status of the awaited Promise is completed
        $promise
            ->then(static fn (mixed $result) => Coroutine::resume($context, $result))
            ->except(static function (mixed $result) use ($context) {
                $result instanceof Throwable
                    ? Coroutine::throw($context, $result)
                    : Coroutine::throw($context, new PromiseRejectException($result));
            });

        // Confirm that you have prepared to handle Fiber recovery and take over control of Fiber by suspending it
        $result = Coroutine::suspend($context);
        if ($result instanceof Promise) {
            return $this->await($result);
        }
        return $result;
    }

    /**
     * @return \Ripple\Coroutine\Context
     */
    public function getContext(): Context
    {
        if (!$fiber = Fiber::getCurrent()) {
            return new SuspensionProxy(EventLoop::getSuspension());
        }
        return ($this->fiber2context[$fiber] ?? null)?->get() ?? new SuspensionProxy(EventLoop::getSuspension());
    }


    /**
     * @param EscapeException $exception
     *
     * @return void
     */
    #[NoReturn]
    public function handleEscapeException(EscapeException $exception): void
    {
        Process::getInstance()->processedInMain($exception->lastWords);
    }


    /**
     * @param int|float $second
     *
     * @return int|float
     * @throws Throwable
     */
    public function sleep(int|float $second): int|float
    {
        $context = getContext();
        delay(static fn () => Coroutine::resume($context, $second), $second);
        return Coroutine::suspend($context);
    }

    /**
     * @param \Ripple\Coroutine\Context $context
     *
     * @return void
     */
    private function processDefers(Context $context): void
    {
        $context->processDefers();
    }

    /**
     * @param \Ripple\Event\Event $event
     *
     * @return void
     */
    protected function dispatchEvent(Event $event): void
    {
        $this->tracer->trace($event);
        if ($event instanceof TerminateEvent) {
            throw new TerminateException($event);
        }
        $this->dispatcher->dispatch($event);
    }
}
