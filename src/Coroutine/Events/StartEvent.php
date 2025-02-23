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

use function spl_object_hash;

class StartEvent extends Event
{
    /**
     * @var Fiber
     */
    private Fiber $fiber;

    /**
     * @param Fiber $fiber
     */
    public function __construct(Fiber $fiber)
    {
        parent::__construct('coroutine.start', ['fiber_id' => spl_object_hash($fiber)]);
        $this->fiber = $fiber;
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
}
