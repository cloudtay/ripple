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

use Ripple\Stream\ConnectionAbortReason;
use RuntimeException;
use Throwable;

/**
 * @internal
 *
 * Internal control-flow exception used exclusively by the reactor to terminate connections.
 * This exception should NEVER be caught by user code or extended by application-level exceptions.
 *
 * When this exception is thrown, it signals that the connection must be immediately closed
 * and all related event monitoring must be cancelled. The reactor's exception boundary
 * will catch this exception, perform cleanup, and emit onClose events.
 *
 * User code should use onClose, onReadableEnd, and onWritableEnd events to handle
 * connection lifecycle events instead of catching this exception.
 *
 * @Author cclilshy
 * @Date   2024/8/16 09:37
 */
final class ConnectionException extends RuntimeException implements AbortConnection
{
    public function __construct(
        public readonly ConnectionAbortReason $reason,
        string $message = '',
        Throwable|null $previous = null
    ) {
        parent::__construct($message ?: $reason->value, 0, $previous);
    }
}
