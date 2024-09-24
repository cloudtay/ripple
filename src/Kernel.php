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
use Fiber;
use Psc\Core\Coroutine\Promise;
use Psc\Utils\Output;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Throwable;

use function call_user_func;
use function chr;
use function define;
use function defined;
use function extension_loaded;
use function file_exists;
use function file_get_contents;
use function fopen;
use function ini_set;
use function intval;
use function ord;
use function pow;
use function preg_match;
use function shell_exec;
use function strlen;

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
    private bool $running = true;

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
     * @Author cclilshy
     * @Date   2024/8/27 21:57
     *
     * @param string $string
     *
     * @return int
     */
    public static function string2int(string $string): int
    {
        $len = strlen($string);
        $sum = 0;
        for ($i = 0; $i < $len; $i++) {
            $sum += (ord($string[$i]) - 96) * pow(26, $len - $i - 1);
        }
        return $sum;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/27 21:57
     *
     * @param int $int
     *
     * @return string
     */
    public static function int2string(int $int): string
    {
        $string = '';
        while ($int > 0) {
            $string = chr(($int - 1) % 26 + 97) . $string;
            $int    = intval(($int - 1) / 26);
        }
        return $string;
    }

    /**
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
     * @param int|float $second
     *
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
     *
     * @return string
     */
    public function delay(Closure $closure, int|float $second): string
    {
        return EventLoop::delay($second, $closure);
    }

    /**
     * @param Closure $closure
     *
     * @return void
     */
    public function defer(Closure $closure): void
    {
        if (!$callback = Coroutine::Coroutine()->getCoroutine()) {
            EventLoop::queue(static function () use ($closure) {
                try {
                    $closure();
                } catch (Throwable $exception) {
                    Output::exception($exception);
                }
            });
            return;
        }

        $callback['promise']->finally(fn () => EventLoop::queue(static function () use ($closure) {
            try {
                $closure();
            } catch (Throwable $exception) {
                Output::exception($exception);
            }
        }));
    }

    /**
     * @param Closure(Closure):void $closure
     * @param int|float             $second
     *
     * @return string
     */
    public function repeat(Closure $closure, int|float $second): string
    {
        return EventLoop::repeat($second, function ($cancelId) use ($closure) {
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
     * @return int
     */
    public function registerForkHandler(Closure $closure): int
    {
        return System::Process()->registerForkHandler($closure);
    }

    /**
     * @param int $index
     *
     * @return void
     */
    public function cancelForkHandler(int $index): void
    {
        System::Process()->cancelForkHandler($index);
    }

    /**
     * @param Closure|null $result
     *
     * @return bool
     */
    public function wait(Closure|null $result = null): bool
    {
        if (!isset($this->mainSuspension)) {
            $this->mainSuspension = EventLoop::getSuspension();
        }

        if (!$this->running) {
            $this->mainSuspension->resume($result);
            try {
                Fiber::suspend();
            } catch (Throwable) {
                exit(1);
            }
        }

        try {
            $this->running = false;
            $result        = $this->mainSuspension->suspend();
            $this->running = true;
            if ($result instanceof Closure) {
                $result();
            }

            /**
             * The Event object may be reset during the running of $result, so mainSuspension needs to be reacquired.
             */
            $this->mainSuspension = EventLoop::getSuspension();
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

    private int $memorySize;
}
