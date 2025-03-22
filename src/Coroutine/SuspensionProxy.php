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

use Throwable;

class SuspensionProxy extends Context implements \Revolt\EventLoop\Suspension
{
    /**
     * @param \Revolt\EventLoop\Suspension $suspension
     */
    public function __construct(protected readonly \Revolt\EventLoop\Suspension $suspension)
    {
        parent::__construct(static fn () => null);
    }

    /**
     * @return mixed
     */
    public function suspend(): mixed
    {
        return $this->suspension->suspend();
    }

    /**
     * @param mixed|null $value
     *
     * @return void
     */
    public function resume(mixed $value = null): void
    {
        $this->suspension->resume($value);
    }

    /**
     * @param Throwable $throwable
     *
     * @return void
     * @throws Throwable
     */
    public function throw(Throwable $throwable): void
    {
        $this->suspension->throw($throwable);
    }
}
