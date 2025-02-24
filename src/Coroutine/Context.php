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

namespace Ripple\Coroutine;

use Closure;
use Fiber;
use Revolt\EventLoop\Suspension;
use Ripple\Coroutine\Events\TerminateEvent;
use Ripple\Event\EventTracer;
use Ripple\Types\Undefined;
use Throwable;

use function Co\getContext;
use function Co\go;
use function spl_object_hash;
use function array_merge;
use function is_array;
use function array_pop;

/**
 * 对`Suspension`接口的兼容只是暂时的,
 * 请任何时候都使用 `Context` 作为类型声明而非 `Suspension`
 */
class Context implements Suspension
{
    /*** @var Fiber */
    public readonly Fiber $fiber;

    /**
     * @var \Ripple\Coroutine\Event\Event[]
     */
    public array $eventQueue = [];

    /**
     * @var array Closure[]
     */
    public array $defers = [];

    /**
     * @param Closure $main
     */
    public function __construct(
        protected Closure $main,
    ) {
        $this->fiber = new Fiber($this->main);
    }

    /**
     * @param mixed|null $value
     *
     * @return void
     * @throws Throwable
     */
    public function resume(mixed $value = null): void
    {
        $this->fiber->resume($value);
    }

    /**
     * @return mixed
     * @throws Throwable
     */
    public function suspend(): mixed
    {
        return Fiber::suspend();
    }

    /**
     * @param Throwable $throwable
     *
     * @return void
     * @throws Throwable
     */
    public function throw(Throwable $throwable): void
    {
        $this->fiber->throw($throwable);
    }

    /**
     * @param array $argv
     *
     * @return mixed
     * @throws Throwable
     */
    public function start(array $argv = []): mixed
    {
        return $this->fiber->start(...$argv);
    }

    /**
     * @return void
     */
    public function terminate(): void
    {
        $this->eventQueue[] = new TerminateEvent();
    }

    /**
     * @param Closure $closure
     *
     * @return void
     */
    public function defer(Closure $closure): void
    {
        $this->defers[] = $closure;
    }

    /**
     * @return void
     */
    public function processDefers(): void
    {
        while ($defer = array_pop($this->defers)) {
            try {
                go($defer);
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * @return array
     */
    public function getTraces(): array
    {
        return EventTracer::getInstance()->getTraces($this);
    }

    /**
     * @var array
     */
    protected static array $context = [];

    /**
     * @param array|string $key
     * @param mixed        $value
     *
     * @return void
     */
    public static function setValue(array|string $key, mixed $value = null): void
    {
        $hash = Context::getHash();
        if (is_array($key)) {
            Context::$context[$hash] = array_merge(Context::$context[$hash] ?? [], $key);
        }

        Context::$context[$hash][$key] = $value;
    }

    /**
     * @param \Ripple\Coroutine\Context|null $context
     *
     * @return string
     */
    public static function getHash(Context|null $context = null): string
    {
        if (!$context) {
            $context = getContext();
        }

        return spl_object_hash($context);
    }

    /**
     * @param string|null $key
     *
     * @return mixed
     */
    public static function getValue(string|null $key = null): mixed
    {
        $hash = Context::getHash();
        if (!$key) {
            return Context::$context[$hash] ?? new Undefined();
        }
        return Context::$context[$hash][$key] ?? new Undefined();
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public static function removeValue(string $key): void
    {
        $hash = Context::getHash();
        unset(Context::$context[$hash][$key]);
    }

    /**
     * @param \Ripple\Coroutine\Context $targetContext
     *
     * @return void
     */
    public static function extend(Context $targetContext): void
    {
        $currentContext = getContext();
        if ($currentContext === $targetContext) {
            return;
        }

        $currentHash = Context::getHash($currentContext);
        $targetHash  = Context::getHash($targetContext);
        Context::$context[$currentHash] = (Context::$context[$targetHash] ?? []);
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        unset(Context::$context[Context::getHash()]);
    }
}
