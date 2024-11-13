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

use Ripple\Stream\StreamInterface;
use Throwable;

class ConnectionTimeoutException extends ConnectionException
{
    public function __construct(
        string          $message = "",
        Throwable       $previous = null,
        StreamInterface $stream = null,
        bool            $close = true
    ) {
        parent::__construct(
            $message,
            ConnectionException::CONNECTION_TIMEOUT,
            $previous,
            $stream,
            $close
        );
    }
}
