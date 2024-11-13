<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Parallel;

use Closure;
use Co\Base;
use Composer\Autoload\ClassLoader;
use parallel\Events;
use parallel\Runtime;
use parallel\Sync;
use ReflectionClass;
use Revolt\EventLoop;
use Ripple\Kernel;
use RuntimeException;
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

/**
 * 2024-08-07
 * 0x00 Allows the USR2 signal to be retained for parallel code execution in the main thread
 * 0x01 Use an independent thread to listen and count instructions to send signals to the main process, retaining the blocking mechanism of events::poll of the main process atomically.
 *
 * PHP version 8.3.0-8.3.8 has memory leak
 */
class Parallel extends Base
{
    /*** @var Base */
    public static Base $instance;

    /*** @var int */
    public static int $cpuCount;

    /*** @var string */
    public static string $autoload;

    /*** @var Events */
    private Events $events;

    /*** @var Future[] */
    private array $futures = [];

    /**
     * @var int
     */
    private int $index;

    /**
     * Event dispatch thread
     *
     * @var Runtime
     */
    private Runtime $counterRuntime;

    /**
     * Event dispatch thread Future
     *
     * @var \parallel\Future
     */
    private \parallel\Future $counterFuture;

    /**
     * Event count channel
     *
     * @var Channel
     */
    private Channel $counterChannel;

    /**
     * Event count scalar
     *
     * @var Sync
     */
    private Sync $eventScalar;

    /*** @var string */
    private string $signalHandlerID;

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
        // Initialize autoloading address
        $reflector          = new ReflectionClass(ClassLoader::class);
        $vendorDir          = dirname($reflector->getFileName(), 2);
        Parallel::$autoload = "{$vendorDir}/autoload.php";

        // get the number of cpu cores
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

        // initialize event handler
        $this->index = 0;
        $this->initializeCounter();
    }

    /**
     * @return void
     */
    private function initializeCounter(): void
    {
        if (isset($this->events)) {
            return;
        }

        $this->events = new Events();
        $this->events->setBlocking(true);

        $this->counterChannel = $this->makeChannel('counter');
        $this->eventScalar    = new Sync(0);
        $this->counterRuntime = new Runtime(Parallel::$autoload);
        $this->counterFuture  = $this->counterRuntime->run(static function (\parallel\Channel $channel, Sync $eventScalar) {
            $eventScalar(fn () => $eventScalar->wait());
            /*** @compatible:Windows */
            if (!Kernel::getInstance()->supportProcessControl()) {
                $processID = getmypid();
            } else {
                $processID = posix_getpid();
            }
            $count = 0;
            while ($number = $channel->recv()) {
                $eventScalar->set($count += $number);
                if ($number > 0) {
                    /**
                     * @compatible:Windows
                     */
                    if (!Kernel::getInstance()->supportProcessControl()) {
                        break;
                    }
                    posix_kill($processID, SIGUSR2);
                } elseif ($count === -1) {
                    break;
                } elseif ($count === 0) {
                    $eventScalar(fn () => $eventScalar->wait());
                }
            }
            return true;
        }, [$this->counterChannel->channel, $this->eventScalar]);

        try {
            $this->signalHandlerID = onSignal(SIGUSR2, fn () => $this->poll());
        } catch (EventLoop\UnsupportedFeatureException) {
        }

        /**
         * The signal processor cannot be unlocked before it is registered.
         */
        defer(function () {
            $this->eventScalar->notify();
        });
    }

    /**
     * @param string   $name
     * @param int|null $capacity
     *
     * @return Channel
     */
    public function makeChannel(string $name, int|null $capacity = null): Channel
    {
        return is_int($capacity)
            ? new Channel(\parallel\Channel::make($name, $capacity))
            : new Channel(\parallel\Channel::make($name));
    }

    /**
     * @param Thread $thread
     * @param        ...$argv
     *
     * @return Future
     */
    public function run(Thread $thread, ...$argv): Future
    {
        if (!isset($this->signalHandlerID)) {
            try {
                $this->signalHandlerID = onSignal(SIGUSR2, fn () => $this->poll());
                defer(function () {
                    $this->eventScalar->notify();
                });
            } catch (EventLoop\UnsupportedFeatureException) {
            }
        }
        $future                       = $thread(...$argv);
        $this->futures[$thread->name] = $future;
        $this->events->addFuture($thread->name, $future->future);
        return $future;
    }

    /**
     * @return void
     */
    private function poll(): void
    {
        while ($number = $this->eventScalar->get()) {
            for ($i = 0; $i < $number; $i++) {
                $event = $this->events->poll();
                if (!$event) {
                    continue;
                }
                $this->counterChannel->send(-1);
                switch ($event->type) {
                    case Events\Event\Type::Cancel:
                    case Events\Event\Type::Kill:
                    case Events\Event\Type::Error:
                        if (isset($this->futures[$event->source])) {
                            $this->futures[$event->source]->onEvent($event);
                            unset($this->futures[$event->source]);
                        }
                        break;
                    case Events\Event\Type::Read:
                        if ($event->object instanceof \parallel\Future) {
                            $name = $event->source;
                            if ($this->futures[$name] ?? null) {
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
            cancel($this->signalHandlerID);
            unset($this->signalHandlerID);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/30 22:11
     * @return static
     * @throws RuntimeException
     */
    public static function getInstance(): static
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            throw new RuntimeException('Parallel is not supported on Windows');
        }

        return parent::getInstance();
    }

    /**
     * @param string $name
     *
     * @return Channel
     */
    public function openChannel(string $name): Channel
    {
        return new Channel(\parallel\Channel::make($name));
    }

    /**
     * @param Closure $closure
     *
     * @return Thread
     */
    public function thread(Closure $closure): Thread
    {
        $name = strval($this->index++);
        return new Thread($closure, $name);
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
