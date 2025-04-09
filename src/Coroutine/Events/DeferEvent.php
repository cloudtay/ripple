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

use Ripple\Coroutine\Context;
use Ripple\Coroutine\Event\Event;
use Closure;

use function Co\go;

class DeferEvent extends Event
{
    /**
     * @param Closure $callback
     * @param Context $coroutineContext
     */
    public function __construct(
        public readonly Closure $callback,
        public readonly Context $coroutineContext
    ) {
        parent::__construct('coroutine.defer', [
            'coroutineContext' => $coroutineContext,
            'callback' => $callback
        ]);
    }

    /**
     * @return bool
     */
    public function handle(): bool
    {
        if ($this->isCancel()) {
            return false;
        }

        go($this->getContext()['callback']);
        return true;
    }
}
