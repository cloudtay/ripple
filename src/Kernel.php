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
use Co\Coroutine;
use Co\System;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Coroutine\Suspension;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Symfony\Component\DependencyInjection\Container;
use Throwable;

use function call_user_func;
use function Co\async;
use function Co\getSuspension;
use function define;
use function defined;
use function extension_loaded;
use function file_exists;
use function file_get_contents;
use function fopen;
use function ini_set;
use function intval;
use function preg_match;
use function shell_exec;

use const PHP_OS_FAMILY;

/**
 * @Author cclilshy
 * @Date   2024/8/29 23:28
 */
class Kernel
{
    /*** @var Kernel */
    public static Kernel $instance;

    /*** @var EventLoop\Suspension */
    private EventLoop\Suspension $mainSuspension;

    /*** @var bool */
    private bool $parallel;

    /*** @var bool */
    private bool $processControl;

    /*** @var bool */
    private bool $mainRunning = true;

    /*** @var Container */
    private Container $container;

    /*** @var int */
    private int $memorySize;

    public function __construct()
    {
        ini_set('memory_limit', -1);
        ini_set('max_execution_time', 0);

        if (!defined('STDIN')) {
            define('STDIN', fopen('php://stdin', 'r'));
        }
        if (!defined('STDOUT')) {
            define('STDOUT', fopen('php://stdout', 'w'));
        }

        $this->mainSuspension = EventLoop::getSuspension();
        $this->parallel       = extension_loaded('parallel');
        $this->processControl = extension_loaded('pcntl') && extension_loaded('posix');
        $this->container      = new Container();
    }

    /**
     * @return Kernel
     */
    public static function getInstance(): Kernel
    {
        if (!isset(Kernel::$instance)) {
            Kernel::$instance = new self();
        }
        return Kernel::$instance;
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
        return Coroutine::Coroutine()->await($promise);
    }

    /**
     * The location of the exception thrown in the async closure may be the calling context/suspension recovery location,
     * so exceptions must be managed carefully.
     *
     * @param Closure $closure
     *
     * @return Promise
     */
    public function async(Closure $closure): Promise
    {
        return Coroutine::Coroutine()->async($closure);
    }

    /**
     * @param Closure $closure
     *
     * @return Promise
     */
    public function promise(Closure $closure): Promise
    {
        return new Promise($closure);
    }

    /**
     * @param Closure   $closure
     * @param int|float $second
     *
     * @return string
     */
    public function delay(Closure $closure, int|float $second): string
    {
        return EventLoop::delay($second, static function () use ($closure) {
            async($closure);
        });
    }

    /**
     * @param Closure $closure
     *
     * @return void
     */
    public function defer(Closure $closure): void
    {
        $suspension = getSuspension();
        if (!$suspension instanceof Suspension) {
            EventLoop::queue(static fn () => async($closure));
            return;
        }

        $suspension->promise->finally(static fn () => async($closure));
    }

    /**
     * @param Closure(Closure):void $closure
     * @param int|float             $second
     *
     * @return string
     */
    public function repeat(Closure $closure, int|float $second): string
    {
        return EventLoop::repeat($second, function (string $cancelId) use ($closure) {
            call_user_func($closure, fn () => $this->cancel($cancelId));
        });
    }

    /**
     * @param string $id
     *
     * @return void
     */
    public function cancel(string $id): void
    {
        EventLoop::cancel($id);
    }

    /**
     * @param int     $signal
     * @param Closure $closure
     *
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signal, Closure $closure): string
    {
        return System::Process()->onSignal($signal, $closure);
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function forked(Closure $closure): string
    {
        return System::Process()->forked($closure);
    }

    /**
     * @param string $index
     *
     * @return void
     */
    public function cancelForked(string $index): void
    {
        System::Process()->cancelForked($index);
    }

    /**
     * @param Closure|null $result
     *
     * @return bool
     * @throws Throwable
     */
    public function wait(Closure|null $result = null): bool
    {
        if (!isset($this->mainSuspension)) {
            $this->mainSuspension = getSuspension();
        }

        if (!$this->mainRunning) {
            try {
                Core\Coroutine\Coroutine::resume($this->mainSuspension, $result);
            } catch (Throwable) {
                exit(1);
            }
        }

        try {
            $this->mainRunning = false;
            $result            = Core\Coroutine\Coroutine::suspend($this->mainSuspension);
            $this->mainRunning = true;
            if ($result instanceof Closure) {
                $result();
            }

            /**
             * The Event object may be reset during the mainRunning of $result, so mainSuspension needs to be reacquired.
             */
            $this->mainSuspension = getSuspension();
            return $this->wait();
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
        foreach (EventLoop::getIdentifiers() as $identifier) {
            $this->cancel($identifier);
        }
    }

    /**
     * @return bool
     */
    public function supportParallel(): bool
    {
        return $this->parallel;
    }

    /**
     * @return bool
     */
    public function supportProcessControl(): bool
    {
        return $this->processControl;
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/24 14:27
     * @return int
     */
    public function getMemorySize(): int
    {
        if (isset($this->memorySize)) {
            return $this->memorySize;
        }

        switch (PHP_OS_FAMILY) {
            case 'Linux':
                if (file_exists('/proc/meminfo')) {
                    $data = file_get_contents("/proc/meminfo");
                    if ($data && preg_match("/MemTotal:\s+(\d+)\skB/", $data, $matches)) {
                        return $this->memorySize = intval(($matches[1] * 1024));
                    }
                }
                break;
            case 'Windows':
                $memory = shell_exec("wmic computersystem get totalphysicalmemory");
                if (preg_match("/\d+/", $memory, $matches)) {
                    return $this->memorySize = intval($matches[0]);
                }
                break;
            case 'Darwin':
                $memory = shell_exec("sysctl -n hw.memsize");
                if ($memory) {
                    return $this->memorySize = intval($memory);
                }
                break;
        }

        return $this->memorySize = 0;
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/30 09:58
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }
}
