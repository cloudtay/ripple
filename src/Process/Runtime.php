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

namespace Ripple\Process;

use Closure;
use Ripple\Kernel;
use Ripple\Promise;
use Throwable;

use function getmypid;
use function posix_kill;

use const SIGKILL;
use const SIGTERM;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:36
 */
class Runtime
{
    /**
     * @param int     $processID
     * @param Promise $promise
     */
    public function __construct(
        protected readonly Promise $promise,
        protected readonly int     $processID,
    ) {
    }

    /**
     * @return int
     */
    public function getProcessID(): int
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            return getmypid();
        }

        return $this->processID;
    }

    /**
     * @param Closure $then
     *
     * @return Promise
     */
    public function then(Closure $then): Promise
    {
        return $this->promise->then($then);
    }

    /**
     * @param Closure $catch
     *
     * @return Promise
     */
    public function except(Closure $catch): Promise
    {
        return $this->promise->except($catch);
    }

    /**
     * @param Closure $finally
     *
     * @return Promise
     */
    public function finally(Closure $finally): Promise
    {
        return $this->promise->finally($finally);
    }

    /***
     * @return mixed
     * @throws Throwable
     */
    public function await(): mixed
    {
        return $this->getPromise()->await();
    }

    /**
     * @return Promise
     */
    public function getPromise(): Promise
    {
        return $this->promise;
    }

    /**
     * @param bool $force
     *
     * @return void
     */
    public function terminate(bool $force = false): void
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            return;
        }

        $force
            ? $this->kill()
            : $this->signal(SIGTERM);
    }

    /**
     * @return void
     */
    public function kill(): void
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            return;
        }

        posix_kill($this->processID, SIGKILL);
    }

    /**
     * @param int $signal
     *
     * @return void
     */
    public function signal(int $signal): void
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            return;
        }

        posix_kill($this->processID, $signal);
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->promise->getStatus() === Promise::PENDING;
    }
}
