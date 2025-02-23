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

namespace Ripple;

use Iterator;
use Ripple\Coroutine\Coroutine;
use Throwable;

use function array_shift;
use function assert;
use function Co\getContext;

class Futures implements Iterator
{
    /*** @var int */
    protected int $index = 0;

    /*** @var array */
    protected array $results = [];

    /*** @var \Ripple\Coroutine\Context[] */
    protected array $waiters = [];

    /*** @param \Ripple\Promise[] $promises */
    public function __construct(protected readonly array $promises)
    {
        foreach ($promises as $promise) {
            assert($promise instanceof Promise, 'All elements must be instances of Promise');

            $promise->finally(function ($result) {
                $this->results[] = $result;
                while ($waiter = array_shift($this->waiters)) {
                    Coroutine::resume($waiter, $result);
                }
            });
        }
    }

    /**
     * @return mixed
     * @throws Throwable
     */
    public function current(): mixed
    {
        if (isset($this->results[$this->index])) {
            return $this->results[$this->index];
        } else {
            $this->waiters[] = $context = getContext();
            return Coroutine::suspend($context);
        }
    }

    /**
     * @return void
     */
    public function next(): void
    {
        $this->index++;
    }

    /**
     * @return int
     */
    public function key(): int
    {
        return $this->index;
    }

    /**
     * @return bool
     */
    public function valid(): bool
    {
        return isset($this->promises[$this->index]);
    }

    /**
     * @return void
     */
    public function rewind(): void
    {
        $this->index = 0;
    }
}
