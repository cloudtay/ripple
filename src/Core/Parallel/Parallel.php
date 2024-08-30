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

namespace Psc\Core\Parallel;

use Closure;
use Composer\Autoload\ClassLoader;
use parallel\Events;
use parallel\Runtime;
use parallel\Sync;
use Psc\Core\LibraryAbstract;
use ReflectionClass;
use Revolt\EventLoop;
use Throwable;

use function Co\cancel;
use function Co\defer;
use function Co\onSignal;
use function count;
use function dirname;
use function file_exists;
use function getmypid;
use function intval;
use function is_int;
use function posix_getpid;
use function posix_kill;
use function preg_match;
use function shell_exec;
use function strval;

use const SIGUSR2;
use const PHP_OS_FAMILY;

/**
 * 2024-08-07
 * 0x00 允许保留USR2信号，以便在主线程中执行并行代码
 * 0x01 用独立线程监听计数指令向主进程发送信号,原子性保留主进程events::poll的堵塞机制
 *
 * PHP版本:8.3.0-8.3.8存在内存泄漏
 */
class Parallel extends LibraryAbstract
{
    /*** @var LibraryAbstract */
    public static LibraryAbstract $instance;

    /*** @var int */
    public static int $cpuCount;

    /*** @var string */
    public static string $autoload;

    /*** @var Events */
    private Events $events;

    /*** @var Future[] */
    private array $futures = [];

    /**
     * 索引
     * @var int
     */
    private int $index;

    /**
     * 事件分发线程
     * @var Runtime
     */
    private Runtime $counterRuntime;

    /**
     * 事件分发线程Future
     * @var \parallel\Future
     */
    private \parallel\Future $counterFuture;

    /**
     * 事件计数通道
     * @var Channel
     */
    private Channel $counterChannel;

    /**
     * 事件计数标量
     * @var Sync
     */
    private Sync $eventScalar;

    /*** @var string */
    private string $signalHandlerId;

    /**
     * Parallel constructor.
     */
    protected function __construct()
    {
        $this->initialize();
    }

    /**
     * @return void
     */
    private function initialize(): void
    {
        // 初始化自动加载地址
        $reflector = new ReflectionClass(ClassLoader::class);
        $vendorDir = dirname($reflector->getFileName(), 2);
        Parallel::$autoload = "{$vendorDir}/autoload.php";

        // 获取CPU核心数
        Parallel::$cpuCount = intval(
            // if
            file_exists('/usr/bin/nproc')
                ? shell_exec('/usr/bin/nproc')
                : ( //else
                    file_exists('/proc/cpuinfo') && preg_match('/^processor\s+:/', shell_exec('cat /proc/cpuinfo'), $matches)
                        ? count($matches)
                        : ( //else
                            shell_exec('sysctl -n hw.ncpu')
                                ? shell_exec('sysctl -n hw.ncpu')
                                : 1 //else
                        )
                )
        );

        // 初始化事件处理器
        $this->index = 0;
        $this->initializeCounter();
    }

    /**
     * @return void
     */
    private function initializeCounter(): void
    {
        if(isset($this->events)) {
            return;
        }

        $this->events = new Events();
        $this->events->setBlocking(true);

        // 初始化标量同步器
        $this->counterChannel = $this->makeChannel('counter');
        $this->eventScalar = new Sync(0);
        $this->counterRuntime = new Runtime();
        $this->counterFuture = $this->counterRuntime->run(static function ($channel, $eventScalar) {
            $eventScalar(fn () => $eventScalar->wait());
            /**
             * @compatible:Windows
             */
            if (PHP_OS_FAMILY === 'Windows') {
                $processId = getmypid();
            } else {
                $processId = posix_getpid();
            }
            $count = 0;
            while($number = $channel->recv()) {
                $eventScalar->set($count += $number);
                if($number > 0) {
                    /**
                     * @compatible:Windows
                     */
                    if (PHP_OS_FAMILY === 'Windows') {
                        break;
                    }
                    posix_kill($processId, SIGUSR2);
                } elseif($count === -1) {
                    break;
                } elseif($count === 0) {
                    $eventScalar(fn () => $eventScalar->wait());
                }
            }
            return true;
        }, [$this->counterChannel->channel, $this->eventScalar]);

        try {
            $this->signalHandlerId = onSignal(SIGUSR2, fn () => $this->poll());
        } catch (EventLoop\UnsupportedFeatureException) {
        }

        //不可在信号处理器未注册前解锁
        defer(function () {
            $this->eventScalar->notify();
        });
    }

    /**
     * @return void
     */
    private function poll(): void
    {
        while($number = $this->eventScalar->get()) {
            for ($i = 0; $i < $number; $i++) {
                $event =  $this->events->poll();
                if(!$event) {
                    continue;
                }
                $this->counterChannel->send(-1);
                switch ($event->type) {
                    case Events\Event\Type::Cancel:
                    case Events\Event\Type::Kill:
                    case Events\Event\Type::Error:
                        if(isset($this->futures[$event->source])) {
                            $this->futures[$event->source]->onEvent($event);
                            unset($this->futures[$event->source]);
                        }
                        break;
                    case Events\Event\Type::Read:
                        if($event->object instanceof \parallel\Future) {
                            $name = $event->source;
                            if($this->futures[$name] ?? null) {
                                try {
                                    $this->futures[$name]->resolve();
                                } catch (Throwable) {
                                } finally {
                                    unset($this->futures[$name]);
                                }
                            }
                        }
                        break;
                }
            }
        }

        if (empty($this->futures)) {
            cancel($this->signalHandlerId);
            unset($this->signalHandlerId);
        }
    }

    /**
     * @param string $name
     * @return Channel
     */
    public function openChannel(string $name): Channel
    {
        return new Channel(\parallel\Channel::make($name));
    }

    /**
     * @param string   $name
     * @param int|null $capacity
     * @return Channel
     */
    public function makeChannel(string $name, ?int $capacity = null): Channel
    {
        return is_int($capacity)
            ? new Channel(\parallel\Channel::make($name, $capacity))
            : new Channel(\parallel\Channel::make($name));
    }

    /**
     * @param Closure $closure
     * @return Thread
     */
    public function thread(Closure $closure): Thread
    {
        $name = strval($this->index++);
        return new Thread($closure, $name);
    }

    /**
     * @param Thread $thread
     * @param        ...$argv
     * @return Future
     */
    public function run(Thread $thread, ...$argv): Future
    {
        if(!isset($this->signalHandlerId)) {
            try {
                $this->signalHandlerId = onSignal(SIGUSR2, fn () => $this->poll());
                defer(function () {
                    $this->eventScalar->notify();
                });
            } catch (EventLoop\UnsupportedFeatureException) {
            }
        }
        $future = $thread(...$argv);
        $this->futures[$thread->name] = $future;
        $this->events->addFuture($thread->name, $future->future);
        return $future;
    }

    /**
     * @throws Throwable
     */
    public function __destruct()
    {
        $this->eventScalar->notify();
        $this->counterChannel->send(-1);
        $this->counterFuture->value();
        $this->counterRuntime->close();
        $this->counterChannel->close();
    }
}
