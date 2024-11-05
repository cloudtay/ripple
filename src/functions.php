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

namespace Co;

use BadFunctionCallException;
use Closure;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Channel;
use Ripple\File\Lock\Lock;
use Ripple\Kernel;
use Ripple\Parallel;
use Ripple\Parallel\Thread;
use Ripple\Proc;
use Ripple\Proc\Session;
use Ripple\Promise;
use Ripple\Utils\Output;
use RuntimeException;
use Throwable;

use function spl_object_hash;

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
    return Kernel::getInstance()->await($promise);
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
    return Kernel::getInstance()->async($closure);
}

/**
 * @param Closure $closure
 *
 * @return Promise
 */
function promise(Closure $closure): Promise
{
    return Kernel::getInstance()->promise($closure);
}

/**
 * @param int|float $second
 *
 * @return int|float
 */
function sleep(int|float $second): int|float
{
    try {
        return \Ripple\Coroutine::getInstance()->sleep($second);
    } catch (Throwable $e) {
        throw new BadFunctionCallException($e->getMessage(), $e->getCode(), $e);
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
    return Kernel::getInstance()->delay($closure, $second);
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
 *
 * @return Thread
 * @throws RuntimeException
 */
function thread(Closure $closure): Thread
{
    return Parallel::getInstance()->thread($closure);
}

/**
 * @param string $id
 *
 * @return void
 */
function cancel(string $id): void
{
    Kernel::getInstance()->cancel($id);
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
    Kernel::getInstance()->cancelAll();
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

function wait(Closure|null $closure = null): void
{
    try {
        Kernel::getInstance()->wait($closure);
    } catch (Throwable $e) {
        throw new BadFunctionCallException($e->getMessage(), $e->getCode(), $e);
    }
}

/**
 * @return void
 */
function stop(): void
{
    Kernel::getInstance()->stop();
}

/**
 * @Description please use forked instead.
 *
 * @param Closure $closure
 *
 * @return string
 */
function registerForkHandler(Closure $closure): string
{
    Output::warning('registerForkHandler is deprecated, please use forked instead.');
    return forked($closure);
}

/**
 * @Description please use cancelForked instead.
 *
 * @param string $eventID
 *
 * @return void
 */
function cancelForkHandler(string $eventID): void
{
    Output::warning('cancelForkHandler is deprecated, please use cancelForked instead.');
    Kernel::getInstance()->cancelForked($eventID);
}

/**
 * @Author cclilshy
 * @Date   2024/10/7 17:15
 * @return EventLoop\Suspension
 */
function getSuspension(): EventLoop\Suspension
{
    return \Ripple\Coroutine::getInstance()->getSuspension();
}

/**
 * @return string
 */
function getID(): string
{
    return spl_object_hash(getSuspension());
}

/**
 * @param string $name
 *
 * @return \Ripple\Channel
 */
function channel(string $name): Channel
{
    return Channel::make($name);
}

/**
 * @param string $name
 *
 * @return \Ripple\File\Lock\Lock
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
    return Proc::getInstance()->open($entrance);
}
