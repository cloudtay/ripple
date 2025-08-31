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
 * 连接握手异常
 * 
 * 用于表示连接握手过程中的错误，如 SSL/TLS 握手失败等。
 * 这是应用层异常，用户可以捕获并处理（如重试、降级等）。
 */
class ConnectionHandshakeException extends ConnectionException
{
    public function __construct(
        string          $message = "",
        Throwable       $previous = null,
        StreamInterface $stream = null,
    ) {
        parent::__construct(
            $message,
            ConnectionException::CONNECTION_HANDSHAKE_FAIL,
            $previous,
            $stream,
        );
    }
}