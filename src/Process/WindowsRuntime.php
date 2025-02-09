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

use function getmypid;

class WindowsRuntime extends Runtime
{
    /**
     * @return void
     */
    public function kill(): void
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            return;
        }

        parent::kill();
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

        parent::signal($signal);
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

        return parent::getProcessID();
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

        parent::terminate($force);
    }
}
