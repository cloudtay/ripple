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

use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:37
 */
class ConnectionException extends Exception
{
    public const           CONNECTION_ERROR          = 1;
    public const           CONNECTION_CLOSED         = 2;
    public const           CONNECTION_TIMEOUT        = 4;
    public const           CONNECTION_WRITE_FAIL     = 8;
    public const           CONNECTION_READ_FAIL      = 16;
    public const           CONNECTION_HANDSHAKE_FAIL = 32;
    public const           CONNECTION_ACCEPT_FAIL    = 64;
    public const           ERROR_ILLEGAL_CONTENT     = 128;
    public const           CONNECTION_CRYPTO         = 256;

    public function __construct(
        string               $message = "",
        int                  $code = 0,
        Throwable|null       $previous = null,
        StreamInterface|null $stream = null,
        bool                 $close = true
    ) {
        parent::__construct($message, $code, $previous);
        $close && $stream?->close();
    }
}
