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

namespace Psc\Store\Coroutine;

use Closure;
use Fiber;
use Psc\Core\Coroutine\Exception;
use Psc\Core\Coroutine\Promise;
use Psc\Core\StoreAbstract;
use Throwable;
use function spl_object_hash;

class Async extends StoreAbstract
{
    /**
     * @var StoreAbstract
     */
    protected static StoreAbstract $instance;

    /**
     * @var Promise[]
     */
    private array $fiber2promise;


    /**
     * @param Promise $promise
     * @return mixed
     * @throws Throwable
     */
    public function await(Promise $promise): mixed
    {
        $fiber = Fiber::getCurrent();

        if (!$fiber) {
            throw new Exception('The await function must be called in a coroutine.');
        }

        if ($promise->getStatus() === Promise::FULFILLED) {
            return $promise->getResult();
        }

        if ($promise->getStatus() === Promise::REJECTED) {
            throw $promise->getResult();
        }

        \P\promise(function ($r, $d) use ($promise) {
            $promise->then(fn($result) => $r($result));
            $promise->except(fn($e) => $d($e));
        })
            ->then(function ($result) use ($fiber) {
                try {
                    $fiber->resume($result);
                } catch (Throwable $e) {
                    $promise = $this->fiber2promise[spl_object_hash($fiber)];
                    $promise->onFiberException($e);
                }
            })
            ->except(function ($e) use ($fiber) {
                try {
                    $fiber->throw($e);
                } catch (Throwable $e) {
                    $promise = $this->fiber2promise[spl_object_hash($fiber)];
                    $promise->onFiberException($e);
                }
            });

        return $fiber->suspend();
    }

    /**
     * @param Closure $closure
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        $fiber = new Fiber($closure);
        return new Promise(function ($r, $d, $promise) use ($fiber) {
            $this->fiber2promise[spl_object_hash($fiber)] = $promise;

            $promise->finally(function () use ($fiber) {
                unset($this->fiber2promise[spl_object_hash($fiber)]);
            });

            $fiber->start($r, $d);
        });
    }
}
