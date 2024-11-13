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

use Closure;
use RuntimeException;

class EscapeException extends RuntimeException
{
    /**
     * @param Closure $lastWords
     */
    public function __construct(public readonly Closure $lastWords)
    {
        parent::__construct('Escape from coroutine');
    }
}
