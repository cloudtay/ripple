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

namespace P;

use Closure;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Parallel\Thread;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

/**
 * @Description 请使用Co\await代替
 *
 * @param Promise $promise
 *
 * @return mixed
 * @throws Throwable
 */
function await(Promise $promise): mixed
{
    return \Co\await($promise);
}

/**
 * @Description 请使用Co\async代替
 * async闭包中抛出的异常落地位置可能为调用上下文/挂起恢复处,因此对异常的管理要谨慎
 *
 * @param Closure $closure
 *
 * @return Promise
 */
function async(Closure $closure): Promise
{
    return \Co\async($closure);
}

/**
 * @Description 请使用Co\promise代替
 *
 * @param Closure $closure
 *
 * @return Promise
 */
function promise(Closure $closure): Promise
{
    return \Co\promise($closure);
}

/**
 * @Description 请使用Co\sleep代替
 *
 * @param int|float $second
 *
 * @return void
 */
function sleep(int|float $second): void
{
    \Co\sleep($second);
}


/**
 * @Description 请使用Co\delay代替
 *
 * @param Closure   $closure
 * @param int|float $second
 *
 * @return string
 */
function delay(Closure $closure, int|float $second): string
{
    return \Co\delay($closure, $second);
}


/**
 * @Description 请使用Co\repeat代替
 *
 * @param Closure(Closure):void $closure
 * @param int|float             $second
 *
 * @return string
 */
function repeat(Closure $closure, int|float $second): string
{
    return \Co\repeat($closure, $second);
}

/**
 * @Description 请使用Co\queue代替
 * @Author      cclilshy
 * @Date        2024/8/29 00:07
 *
 * @param Closure $closure
 *
 * @return void
 */
function queue(Closure $closure): void
{
    \Co\queue($closure);
}

/**
 * @Description 请使用Co\defer代替
 *
 * @param Closure $closure
 *
 * @return void
 */
function defer(Closure $closure): void
{
    \Co\defer($closure);
}

/**
 * @Description 请使用Co\thread代替
 *
 * @param Closure $closure
 *
 * @return Thread
 */
function thread(Closure $closure): Thread
{
    return \Co\thread($closure);
}

/**
 * @Description 请使用Co\fork代替
 *
 * @param string $id
 *
 * @return void
 */
function cancel(string $id): void
{
    \Co\cancel($id);
}

/**
 * @Description 请使用Co\fork代替
 *
 * @param int $index
 *
 * @return void
 */
function cancelForkHandler(int $index): void
{
    \Co\cancelForkHandler($index);
}

/**
 * @Description 请使用Co\cancelAll代替
 * @return void
 */
function cancelAll(): void
{
    \Co\cancelAll();
}

/**
 * @Description 请使用Co\onSignal代替
 *
 * @param int     $signal
 * @param Closure $closure
 *
 * @return string
 * @throws UnsupportedFeatureException
 */
function onSignal(int $signal, Closure $closure): string
{
    return \Co\onSignal($signal, $closure);
}

/**
 * @Description 请使用Co\registerForkHandler代替
 *
 * @param Closure $closure
 *
 * @return int
 */
function registerForkHandler(Closure $closure): int
{
    return \Co\registerForkHandler($closure);
}


/**
 * @Description 请使用Co\tick代替
 *
 * @param Closure|null $closure
 *
 * @return void
 */
function tick(Closure|null $closure = null): void
{
    \Co\tick($closure);
}

/**
 * @Description 请使用Co\stop代替
 * @return void
 */
function stop(): void
{
    \Co\stop();
}
