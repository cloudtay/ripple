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

use Closure;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Parallel\Thread;
use Psc\Kernel;
use Psc\Utils\Output;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Container;
use Throwable;

/**
 * @param Promise $promise
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
 * @return int
 */
function sleep(int|float $second): int
{
    return Coroutine::Coroutine()->sleep($second);
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
    return System::Parallel()->thread($closure);
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
 * @param string $eventId
 *
 * @return void
 */
function cancelForked(string $eventId): void
{
    Kernel::getInstance()->cancelForked($eventId);
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
 * @Date   2024/9/30 10:54
 * @return Container
 */
function container(): Container
{
    return Coroutine::Coroutine()->getContainer();
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
 * @param string $eventId
 *
 * @return void
 */
function cancelForkHandler(string $eventId): void
{
    Output::warning('cancelForkHandler is deprecated, please use cancelForked instead.');
    Kernel::getInstance()->cancelForked($eventId);
}

/**
 * @Author cclilshy
 * @Date   2024/10/7 17:15
 * @return EventLoop\Suspension
 */
function getSuspension(): EventLoop\Suspension
{
    return Coroutine::Coroutine()->getSuspension();
}
