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

use Composer\Autoload\ClassLoader;
use parallel\Events;
use parallel\Runtime;
use ReflectionClass;
use Ripple\Channel\Channel;
use Ripple\Kernel;
use Ripple\Support;
use Closure;
use Exception;

use function Co\async;
use function Co\forked;
use function dirname;
use function extension_loaded;

if (!extension_loaded('parallel')) {
    return;
}

/**
 * 2024-08-07
 * 0x00 Allows the USR2 signal to be retained for parallel code execution in the main thread
 * 0x01 Use an independent thread to listen and count instructions to send signals to the main process,
 * retaining the blocking mechanism of events::poll of the main process atomically.
 *
 * PHP version 8.3.0-8.3.8 has memory leak
 */
class Parallel extends Support
{
    /**
     *
     */
    private const SCALAR_CHANNEL_PREFIX = 'ripple.parallel.scalar.';

    /*** @var Support */
    public static Support $instance;

    /*** @var string */
    private string $autoloadFile;

    /*** @var \Ripple\Channel\Channel */
    private Channel $channel;

    /*** @var Events */
    private Events $events;

    /**
     * @var int
     */
    private int $index = 0;

    /**
     * @var Future[]
     */
    private array $futures = [];

    /**
     * Parallel constructor.
     */
    protected function __construct()
    {
        // Initialize autoload file path
        $reflector          = new ReflectionClass(ClassLoader::class);
        $vendorDir          = dirname($reflector->getFileName(), 2);
        $this->autoloadFile = "{$vendorDir}/autoload.php";
        $this->events = new Events();
        $this->events->setBlocking(true);
        $this->channel = Parallel::openScalarChannel(true);
        $this->registerForked();
    }

    /**
     * @param bool $owner
     *
     * @return \Ripple\Channel\Channel
     */
    public static function openScalarChannel(bool $owner = false): Channel
    {
        return \Co\channel(Parallel::SCALAR_CHANNEL_PREFIX . Kernel::getInstance()->getProcessId(), $owner);
    }

    /**
     * @return void
     */
    public function registerForked(): void
    {
        forked(function () {
            foreach ($this->futures as $future) {
                $future->reject(new Exception('Future was cancelled.'));
                $future->cancel();
            }

            $this->index  = 0;
            $this->events = new Events();
            $this->events->setBlocking(true);
            $this->channel = Parallel::openScalarChannel(true);
            $this->registerForked();
        });
    }

    /**
     * @param Closure $closure
     * @param array $args
     *
     * @return Future
     */
    public function run(Closure $closure, array $args = []): Future
    {
        $thread = new Thread($closure, $args);
        if ($this->events->count() === 0) {
            $this->registerPoll();
        }

        $future = $thread(new Runtime($this->autoloadFile));
        $name   = "future-{$this->index}";
        $this->index++;
        $this->events->addFuture($name, $future->getParallelFuture());
        $this->futures[$name] = $future;
        return $future;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->events->count();
    }

    /**
     * @return void
     */
    public function registerPoll(): void
    {
        async(function () {
            while ($this->channel->receive()) {
                $this->poll();

                if ($this->events->count() === 0) {
                    break;
                }
            }
        });
    }

    /**
     * @return void
     */
    public function poll(): void
    {
        if (!$event = $this->events->poll()) {
            return;
        }

        switch ($event->type) {
            case Events\Event\Type::Kill:
            case Events\Event\Type::Cancel:
                if ($future = $this->futures[$event->source] ?? null) {
                    $future->reject(new Exception('Future was cancelled.'));
                    unset($this->futures[$event->source]);
                }
                break;

            case Events\Event\Type::Error:
                if ($future = $this->futures[$event->source] ?? null) {
                    $future->reject($event->value);
                    unset($this->futures[$event->source]);
                }
                break;

            case Events\Event\Type::Read:
                if ($future = $this->futures[$event->source] ?? null) {
                    $future->resolve($event->value);
                    unset($this->futures[$event->source]);
                }
                break;
        }
    }
}
