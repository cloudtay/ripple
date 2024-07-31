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

namespace Psc;

use Closure;
use P\Coroutine;
use P\System;
use Psc\Core\Coroutine\Promise;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

use function call_user_func;
use function count;

class Kernel
{
    /**
     * @var Kernel
     */
    public static Kernel $instance;

    /**
     *
     */
    public function __construct()
    {
        $this->mainSuspension = EventLoop::getSuspension();
    }

    /**
     * @return Kernel
     */
    public static function getInstance(): Kernel
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @param Promise $promise
     * @return mixed
     * @throws Throwable
     */
    public function await(Promise $promise): mixed
    {
        return Coroutine::Coroutine()->await($promise);
    }

    /**
     * async闭包中抛出的异常落地位置可能为调用上下文/挂起恢复处,因此对异常的管理要谨慎
     * @param Closure $closure
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        return Coroutine::Coroutine()->async($closure);
    }

    /**
     * @param Closure $closure
     * @return Promise
     */
    public function promise(Closure $closure): Promise
    {
        return new Promise($closure);
    }

    /**
     * @param int|float $second
     * @return void
     */
    public function sleep(int|float $second): void
    {
        $suspension = EventLoop::getSuspension();
        $this->delay(fn () => $suspension->resume(), $second);
        $suspension->suspend();
    }

    /**
     * @param Closure   $closure
     * @param int|float $second
     * @return string
     */
    public function delay(Closure $closure, int|float $second): string
    {
        return EventLoop::delay($second, $closure);
    }

    /**
     * @param Closure $closure
     * @return void
     */
    public function defer(Closure $closure): void
    {
        if (!$callback = Coroutine::Coroutine()->getCoroutine()) {
            EventLoop::defer($closure);
            return;
        }

        $callback['promise']->finally(fn () => EventLoop::defer($closure));
    }

    /**
     * @param string $id
     * @return void
     */
    public function cancel(string $id): void
    {
        EventLoop::cancel($id);
    }

    /**
     * @param Closure(Closure):void $closure
     * @param int|float             $second
     * @return string
     */
    public function repeat(Closure $closure, int|float $second): string
    {
        return EventLoop::repeat($second, function ($cancelId) use ($closure) {
            call_user_func($closure, fn () => $this->cancel($cancelId));
        });
    }

    /**
     * @param int     $signal
     * @param Closure $closure
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signal, Closure $closure): string
    {
        return System::Process()->onSignal($signal, $closure);
    }

    /**
     * @param Closure $closure
     * @return int
     */
    public function registerForkHandler(Closure $closure): int
    {
        return System::Process()->registerForkHandler($closure);
    }

    /**
     * @param int $index
     * @return void
     */
    public function cancelForkHandler(int $index): void
    {
        System::Process()->cancelForkHandler($index);
    }

    /*** @var EventLoop\Suspension */
    private EventLoop\Suspension $mainSuspension;

    /**
     * @return void
     */
    public function run(): void
    {
        while (1) {
            if ($this->tick()) {
                continue;
            }

            break;
        }
    }

    /**
     * @return bool
     */
    public function tick(): mixed
    {
        if (count($this->getIdentities()) === 0) {
            //nothing to do
            return false;
        }

        try {
            return $this->mainSuspension->suspend();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        EventLoop::getDriver()->stop();
    }

    /**
     * @return void
     */
    public function cancelAll(): void
    {
        foreach ($this->getIdentities() as $identifier) {
            $this->cancel($identifier);
        }
    }

    /**
     * @return array
     */
    public function getIdentities(): array
    {
        return EventLoop::getIdentifiers();
    }
}
