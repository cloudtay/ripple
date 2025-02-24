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

use LogicException;
use Ripple\Coroutine\Coroutine;
use Ripple\Utils\Output;
use RuntimeException;
use Throwable;

use function array_shift;
use function Co\cancel;
use function Co\delay;
use function Co\getContext;
use function spl_object_hash;

class WaitGroup
{
    /*** @var bool */
    protected bool $done = true;

    /*** @var \Ripple\Coroutine\Context[] */
    protected array $waiters = [];

    /*** @var int */
    protected int $count = 0;

    /*** @param int $count */
    public function __construct(int $count = 0)
    {
        $this->add($count);
    }

    /**
     * @param int $delta
     *
     * @return void
     */
    public function add(int $delta = 1): void
    {
        if ($delta > 0) {
            $this->count += $delta;
            $this->done  = false;
        } elseif ($delta < 0) {
            throw new LogicException('delta must be greater than or equal to 0');
        }

        // For the case where $delta is 0, no operation is performed
    }

    /**
     * @return void
     */
    public function done(): void
    {
        if ($this->count <= 0) {
            throw new LogicException('No tasks to mark as done');
        }

        $this->count--;
        if ($this->count === 0) {
            $this->done = true;
            while ($context = array_shift($this->waiters)) {
                try {
                    Coroutine::resume($context);
                } catch (Throwable $exception) {
                    Output::warning($exception->getMessage());
                    continue;
                }
            }
        }
    }

    /**
     * @param int|float $timeout
     *
     * @return void
     */
    public function wait(int|float $timeout = 0): void
    {
        if ($this->done) {
            return;
        }

        $context = getContext();
        $this->waiters[$hash = spl_object_hash($context)] = $context;

        if ($timeout > 0) {
            $timeoutOID = delay(function () use ($hash) {
                $context = $this->waiters[$hash];
                unset($this->waiters[$hash]);
                Coroutine::throw($context, new RuntimeException('WaitGroup timeout'));
            }, $timeout);
        }

        try {
            Coroutine::suspend($context);
            if (isset($timeoutOID)) {
                cancel($timeoutOID);
            }
        } catch (Throwable $exception) {
            throw new RuntimeException($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * @return int
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * @return bool
     */
    public function isDone(): bool
    {
        return $this->done;
    }
}
