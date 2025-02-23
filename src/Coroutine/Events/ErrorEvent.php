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

namespace Ripple\Coroutine\Events;

use Fiber;
use Ripple\Coroutine\Event\Event;
use Throwable;

use function spl_object_hash;

class ErrorEvent extends Event
{
    /**
     * @var Fiber
     */
    private Fiber $fiber;

    /**
     * @var Throwable
     */
    private Throwable $error;

    /**
     * @param Fiber     $fiber
     * @param Throwable $error
     */
    public function __construct(Fiber $fiber, Throwable $error)
    {
        parent::__construct('coroutine.error', [
            'fiber_id' => spl_object_hash($fiber),
            'error' => $error->getMessage()
        ]);
        $this->fiber = $fiber;
        $this->error = $error;
    }

    /**
     * @return bool
     */
    public function handle(): bool
    {
        return true;
    }

    /**
     * @return Fiber
     */
    public function getFiber(): Fiber
    {
        return $this->fiber;
    }

    /**
     * @return Throwable
     */
    public function getError(): Throwable
    {
        return $this->error;
    }
}
