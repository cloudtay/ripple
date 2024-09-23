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

namespace Psc\Core\Stream;

use Closure;
use Psc\Core\Coroutine\Promise;
use Throwable;

use function call_user_func_array;
use function Co\cancel;
use function Co\promise;

final class Transaction
{
    /**
     * @var string
     */
    private string $onReadableId;

    /**
     * @var string
     */
    private string $onWriteableId;

    /**
     * @var string[]
     */
    private array $onCloseIds = [];

    /**
     * @var Closure
     */
    private Closure $resolve;

    /**
     * @var Closure
     */
    private Closure $reject;

    /**
     * @var Promise
     */
    private Promise $promise;

    /**
     * @param Stream $stream
     */
    public function __construct(private readonly Stream $stream)
    {
        $this->promise = promise(function ($resolve, $reject) {
            $this->resolve = $resolve;
            $this->reject  = $reject;
        });
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function onReadable(Closure $closure): string
    {
        return $this->onReadableId = $this->stream->onReadable(function (Stream $stream, Closure $cancel) use ($closure) {
            try {
                call_user_func_array($closure, [$stream, $cancel]);
            } catch (Throwable $exception) {
                $this->fail($exception);
            }
        });
    }

    /**
     * @param Throwable $exception
     *
     * @return void
     */
    public function fail(Throwable $exception): void
    {
        if ($this->promise->getStatus() !== Promise::PENDING) {
            return;
        }

        $this->cancelAll();
        ($this->reject)($exception);
    }

    /**
     * @return void
     */
    private function cancelAll(): void
    {
        foreach ($this->onCloseIds as $id) {
            $this->stream->cancelOnClose($id);
        }

        if (isset($this->onReadableId)) {
            cancel($this->onReadableId);
            unset($this->onReadableId);
        }

        if (isset($this->onWriteableId)) {
            cancel($this->onWriteableId);
            unset($this->onWriteableId);
        }
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function onWriteable(Closure $closure): string
    {
        return $this->onWriteableId = $this->stream->onWritable(function (Stream $stream, Closure $cancel) use ($closure) {
            try {
                call_user_func_array($closure, [$stream, $cancel]);
            } catch (Throwable $exception) {
                $this->fail($exception);
            }
        });
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function onClose(Closure $closure): string
    {
        $id                 = $this->stream->onClose($closure);
        $this->onCloseIds[] = $id;
        return $id;
    }

    /**
     * @return Promise
     */
    public function getPromise(): Promise
    {
        return $this->promise;
    }

    /**
     * @return \Psc\Core\Stream\Stream
     */
    public function getStream(): Stream
    {
        return $this->stream;
    }

    /**
     * @return void
     */
    public function complete(): void
    {
        if ($this->promise->getStatus() !== Promise::PENDING) {
            return;
        }
        $this->cancelAll();
        ($this->resolve)();
    }
}
