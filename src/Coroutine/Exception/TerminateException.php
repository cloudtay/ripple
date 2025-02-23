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

namespace Ripple\Coroutine\Exception;

use Ripple\Coroutine\Event\Event;
use RuntimeException;

class TerminateException extends RuntimeException
{
    /**
     * @param \Ripple\Coroutine\Event\Event|null $event
     */
    public function __construct(public readonly Event|null $event = null)
    {
        parent::__construct('Coroutine terminated');
    }
}
