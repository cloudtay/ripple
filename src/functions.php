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

namespace Co;

use BadFunctionCallException;
use Closure;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Channel\Channel;
use Ripple\Coroutine\Context;
use Ripple\Coroutine\Coroutine;
use Ripple\File\Lock;
use Ripple\Kernel;
use Ripple\Parallel\Future;
use Ripple\Parallel\Parallel;
use Ripple\Proc\Proc;
use Ripple\Proc\Session;
use Ripple\Process\Process;
use Ripple\Process\Task;
use Ripple\Promise;
use Throwable;

use function function_exists;
use function getmypid;

/**
 * @param Closure $closure
 *
 * @return Context
 */
function go(Closure $closure): Context
{
    return Coroutine::getInstance()->go($closure);
}

/**
 * The location of the exception thrown in the async closure may be the calling context/suspension recovery location,
 * so exceptions must be managed carefully.
 *
 * Since the function itself will end soon, there is no point in try-catching the function, so the exception will be thrown into the calling context
 * You can catch exceptions by using the `->except` method, or use the `->await` method to wait for the asynchronous task to complete
 *
 * @param Closure $closure
 *
 * @return Promise
 */
function async(Closure $closure): Promise
{
    return Coroutine::getInstance()->async($closure);
}

/**
 * This method is different from onReject, which allows accepting any type of rejected futures object.
 * When await promise is rejected, an error will be thrown instead of returning the rejected value.
 *
 * If the rejected value is a non-Error object, it will be wrapped into a `PromiseRejectException` object,
 * The `getReason` method of this object can obtain the rejected value
 *
 * @param \Ripple\Promise $promise
 *
 * @return mixed
 * @throws Throwable
 */
function await(Promise $promise): mixed
{
    return Coroutine::getInstance()->await($promise);
}

/**
 * @param Closure $closure
 *
 * @return Promise
 */
function promise(Closure $closure): Promise
{
    return new Promise($closure);
}

/**
 * @param int|float $second
 *
 * @return int|float
 */
function sleep(int|float $second): int|float
{
    try {
        return Coroutine::getInstance()->sleep($second);
    } catch (Throwable $exception) {
        throw new BadFunctionCallException($exception->getMessage(), $exception->getCode(), $exception);
    }
}

/**
 * @param Closure   $closure
 * @param int|float $second
 *
 * @return string
 */
function delay(Closure $closure, int|float $second): string
{
    return EventLoop::delay($second, static function () use ($closure) {
        go($closure);
    });
}


/**
 * @param Closure(Closure):void $closure
 * @param int|float             $second
 *
 * @return string
 */
function repeat(Closure $closure, int|float $second): string
{
    return Kernel::getInstance()->repeat($closure, $second);
}

/**
 * @Author cclilshy
 * @Date   2024/8/29 00:07
 *
 * @param Closure $closure
 *
 * @return void
 */
function queue(Closure $closure): void
{
    EventLoop::queue($closure);
}

/**
 * @param Closure $closure
 *
 * @return void
 */
function defer(Closure $closure): void
{
    Kernel::getInstance()->defer($closure);
}

/**
 * @param Closure $closure
 * @param array   $argv
 *
 * @return \Ripple\Parallel\Future
 */
function thread(Closure $closure, array $argv = []): Future
{
    return Parallel::getInstance()->run($closure, $argv);
}

/**
 * @param string $id
 *
 * @return void
 */
function cancel(string $id): void
{
    EventLoop::cancel($id);
}

/**
 * @param string $eventID
 *
 * @return void
 */
function cancelForked(string $eventID): void
{
    Kernel::getInstance()->cancelForked($eventID);
}

/**
 * @return void
 */
function cancelAll(): void
{
    foreach (EventLoop::getIdentifiers() as $identifier) {
        @EventLoop::cancel($identifier);
    }
}

/**
 * @param int     $signal
 * @param Closure $closure
 *
 * @return string
 * @throws UnsupportedFeatureException
 */
function onSignal(int $signal, Closure $closure): string
{
    return Kernel::getInstance()->onSignal($signal, $closure);
}

/**
 * @param Closure $closure
 *
 * @return string
 */
function forked(Closure $closure): string
{
    return Kernel::getInstance()->forked($closure);
}

/**
 * @param Closure|null $closure
 *
 * @return void
 */
function wait(Closure|null $closure = null): void
{
    Kernel::getInstance()->wait($closure);
}

/**
 * @return void
 */
function stop(): void
{
    Kernel::getInstance()->stop();
}

/**
 * @Author cclilshy
 * @Date   2024/10/7 17:15
 * @return Context
 * @deprecated
 */
function getSuspension(): Context
{
    return getContext();
}

/**
 * @return Context
 */
function getContext(): Context
{
    return Coroutine::getInstance()->getContext();
}

/**
 * @param string $name
 * @param bool $owner
 *
 * @return \Ripple\Channel\Channel
 */
function channel(string $name, bool $owner = false): Channel
{
    return $owner ? Channel::make($name) : Channel::open($name);
}

/**
 * @param string $name
 *
 * @return \Ripple\File\Lock
 */
function lock(string $name): Lock
{
    return new Lock($name);
}

/**
 * @param string|array $entrance
 *
 * @return \Ripple\Proc\Session|false
 */
function proc(string|array $entrance = '/bin/sh'): Session|false
{
    return Proc::open($entrance);
}

/**
 * @param Closure $closure
 *
 * @return \Ripple\Process\Task
 */
function process(Closure $closure): Task
{
    return Process::getInstance()->create($closure);
}

/**
 * @param Context|null $context
 *
 * @return mixed
 * @throws Throwable
 */
function suspend(Context|null $context = null): mixed
{
    if ($context === null) {
        $context = getContext();
    }

    return Coroutine::suspend($context);
}

/**
 * @param Context    $context
 * @param mixed|null $result
 *
 * @return mixed
 */
function resume(Context $context, mixed $result = null): mixed
{
    return Coroutine::resume($context, $result);
}

/**
 * @param Context $context
 * @param Throwable                   $exception
 *
 * @return void
 */
function __throw(Context $context, Throwable $exception): void
{
    Coroutine::throw($context, $exception);
}

/**
 *
 */
if (!function_exists('posix_getpid')) {
    /**
     * @return int
     */
    function posix_getpid(): int
    {
        return getmypid();
    }
}
