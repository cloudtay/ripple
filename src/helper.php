<?php declare(strict_types=1);
/*
 * Copyright (c) 2023 cclilshy
 * Contact Information:
 * Email: jingnigg@gmail.com
 * Website: https://cc.cloudtay.com/
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
 * 版权所有 (c) 2023 cclilshy
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

namespace A;

use Cclilshy\PRippleEvent\Core\Coroutine\Coroutine;
use Cclilshy\PRippleEvent\Core\Coroutine\Exception\Exception;
use Cclilshy\PRippleEvent\Core\Coroutine\Promise;
use Cclilshy\PRippleEvent\Core\Output;
use Cclilshy\PRippleEvent\Core\Stream\Stream;
use Closure;
use Fiber;
use Revolt\EventLoop;
use Throwable;
use function call_user_func;


/**
 * @param Promise $promise
 * @return mixed
 * @throws Throwable
 */
function await(Promise $promise): mixed
{
    $fiber = Fiber::getCurrent();
    if (!$fiber) {
        throw new Exception('The await function must be called in a coroutine.');
    }
    $promise->finally(function (mixed $result) use ($fiber) {
        if ($result instanceof Throwable) {
            $fiber->throw($result);
        } else {
            $fiber->resume($result);
        }
    });
    return $fiber->suspend();
}

/**
 * @param Closure $closure
 * @return Promise
 */
function async(Closure $closure): Promise
{
    return new Coroutine($closure);
}

/**
 * @param Closure $closure
 * @return Promise
 */
function promise(Closure $closure): Promise
{
    return new Promise($closure);
}

/**
 * @param int|float $second
 * @return void
 */
function sleep(int|float $second): void
{
    if (Fiber::getCurrent()) {
        try {
            await(async(function ($r) use ($second) {
                defer($second, function () use ($r) {
                    call_user_func($r);
                });
            }));
        } catch (Throwable $e) {
            Output::exception($e);
        }
    } else {
        defer($second, function () {
        });
    }
}

/**
 * @param string $filename
 * @return Promise
 */
function fileGetContents(string $filename): Promise
{
    return promise(function (Closure $resolve, Closure $reject) use ($filename) {
        $stream = new Stream(fopen($filename, 'r'));
        $stream->setBlocking(false);

        $content = '';
        onReadable($stream, function (Stream $stream) use ($resolve, $reject, &$content) {
            try {
                $fragment = $stream->read(8192);
                $content  .= $fragment;
            } catch (Throwable $e) {
                $stream->close();
                call_user_func($reject, $e);
                return;
            }

            if ($stream->eof()) {
                $stream->close();
                call_user_func($resolve, $content);
            }
        });
    });
}

/**
 * @param int|float $second
 * @param Closure   $closure
 * @return void
 */
function defer(int|float $second, Closure $closure): void
{
    EventLoop::delay($second, $closure);
    Fiber::getCurrent() || EventLoop::run();
}

/**
 * @param string $id
 * @return void
 */
function cancel(string $id): void
{
    EventLoop::cancel($id);
}

/**
 * @param int|float $second
 * @param Closure   $closure
 * @return string
 */
function repeat(int|float $second, Closure $closure): string
{
    return EventLoop::repeat($second, $closure);
}


/**
 * @param int $microseconds
 * @return void
 */
function loop(int $microseconds = 100000): void
{
    while (true) {
        EventLoop::run();
        usleep($microseconds);
    }
}

/**
 * @param Stream  $stream
 * @param Closure $closure
 * @return void
 */
function onReadable(Stream $stream, Closure $closure): void
{
    $id = EventLoop::onReadable($stream->stream, function () use ($closure, $stream) {
        try {
            call_user_func($closure, $stream);
        } catch (Throwable $e) {
            $stream->close();
            Output::exception($e);
        }
    });
    $stream->onClose(fn() => EventLoop::cancel($id));
}

/**
 * @param Stream  $stream
 * @param Closure $closure
 * @return void
 */
function onWritable(Stream $stream, Closure $closure): void
{
    $id = EventLoop::onWritable($stream->stream, function () use ($closure, $stream) {
        try {
            call_user_func($closure, $stream);
        } catch (Throwable $e) {
            $stream->close();
            Output::exception($e);
        }
    });
    $stream->onClose(fn() => EventLoop::cancel($id));
}
