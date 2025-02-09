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

use parallel\Runtime;
use Ripple\WaitGroup;
use Throwable;

use function extension_loaded;

if (!extension_loaded('parallel')) {
    return;
}

class Future
{
    public const STATUS_PENDING   = 0;
    public const STATUS_FULFILLED = 1;
    public const STATUS_REJECTED  = 2;

    /*** @var WaitGroup */
    private WaitGroup $waitGroup;

    /**
     * @var int
     */
    private int $status = Future::STATUS_PENDING;

    /**
     * @var mixed
     */
    private mixed $result;

    /**
     * @param \parallel\Future  $parallelFuture
     * @param \parallel\Runtime $runtime
     */
    public function __construct(private readonly \parallel\Future $parallelFuture, private readonly Runtime $runtime)
    {
        $this->waitGroup = new WaitGroup(1);
    }

    /**
     * @param mixed $result
     *
     * @return void
     */
    public function resolve(mixed $result): void
    {
        $this->status = Future::STATUS_FULFILLED;
        $this->result = $result;
        $this->waitGroup->done();
    }

    /**
     * @return bool
     */
    public function done(): bool
    {
        $this->waitGroup->wait();
        return $this->status === Future::STATUS_FULFILLED;
    }

    /**
     * @param Throwable $exception
     *
     * @return void
     */
    public function reject(Throwable $exception): void
    {
        $this->status = Future::STATUS_REJECTED;
        $this->result = $exception;
        $this->waitGroup->done();
    }

    /**
     * @return int
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return mixed
     * @throws Throwable
     */
    public function value(): mixed
    {
        if (!$this->done()) {
            throw $this->result;
        }
        return $this->result;
    }

    /**
     * @return bool
     */
    public function cancel(): bool
    {
        $bool = $this->getParallelFuture()->cancel();
        Parallel::getInstance()->poll();
        return $bool;
    }

    /**
     * @return \parallel\Future
     */
    public function getParallelFuture(): \parallel\Future
    {
        return $this->parallelFuture;
    }

    /**
     * @return bool
     */
    public function canceled(): bool
    {
        return $this->getParallelFuture()->cancelled();
    }

    /**
     * @return void
     */
    public function kill(): void
    {
        $this->getRuntime()->kill();
        Parallel::getInstance()->poll();
    }

    /**
     * @return \parallel\Runtime
     */
    public function getRuntime(): Runtime
    {
        return $this->runtime;
    }
}
