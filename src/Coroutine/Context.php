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
use Ripple\Coroutine\Event\EventTracer;
use Throwable;

use function Co\getContext;
use function spl_object_hash;

/**
 * Compatibility with the 'Suspension' interface is only temporary,
 * Please always use 'Context' as a type declaration instead of 'Suspension'
 */
class Context extends ContextData implements Suspension
{
    /*** @var Fiber */
    public readonly Fiber $fiber;

    /**
     * @var bool
     */
    public bool $isTerminate = false;

    /**
     * @param Closure $main
     */
    public function __construct(protected Closure $main)
    {
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
        $this->isTerminate = true;
    }

    /**
     * @return array
     */
    public function getTraces(): array
    {
        return EventTracer::getInstance()->getTraces($this);
    }

    /**
     * @param Context|null $context
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
}
