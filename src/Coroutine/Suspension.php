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
use Ripple\Promise;
use Throwable;

class Suspension implements \Revolt\EventLoop\Suspension
{
    /*** @var Fiber */
    public readonly Fiber $fiber;

    /**
     * @param Closure $main
     * @param Closure $resolve
     * @param Closure $reject
     * @param Promise $promise
     */
    public function __construct(
        public readonly Closure $main,
        public readonly Closure $resolve,
        public readonly Closure $reject,
        public readonly Promise $promise
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
     * @return mixed
     * @throws Throwable
     */
    public function start(): mixed
    {
        return $this->fiber->start($this->resolve, $this->reject);
    }

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function resolve(mixed $value): void
    {
        ($this->resolve)($value);
    }

    /**
     * @param mixed $throwable
     *
     * @return void
     */
    public function reject(mixed $throwable): void
    {
        ($this->reject)($throwable);
    }
}
