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

namespace Ripple\Stream\Exception;

use Throwable;

/**
 * Timeout exception that can be handled by user code
 * This represents a recoverable timeout condition
 */
class ConnectionTimeoutException extends TransportTimeoutException
{
    public function __construct(
        string $message = "Connection timeout",
        Throwable|null $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
