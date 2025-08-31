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
 * 连接状态异常
 * 
 * 用于表示在主动检测连接状态时发现的异常情况。
 * 这是应用层异常，用户可以捕获并处理。
 * 
 * 使用场景：
 * - 批量操作中主动检测连接状态
 * - 连接池健康检查
 * - 协议层状态机中的连接验证
 * - 半关闭连接检测
 */
class ConnectionStateException extends ConnectionException
{
    // 连接状态常量
    public const PEER_CLOSED = 'peer_closed';           // 对端正常关闭
    public const HALF_CLOSED = 'half_closed';           // TCP 半关闭状态
    public const CONNECTION_LOST = 'connection_lost';   // 连接异常丢失
    public const WRITE_CLOSED = 'write_closed';         // 写端关闭
    public const READ_CLOSED = 'read_closed';           // 读端关闭

    public function __construct(
        string          $message = "",
        public readonly string $reason = self::CONNECTION_LOST,
        Throwable       $previous = null,
        StreamInterface $stream = null,
    ) {
        parent::__construct(
            $message,
            ConnectionException::CONNECTION_CLOSED,
            $previous,
            $stream,
        );
    }

    /**
     * 判断是否为优雅关闭
     */
    public function isGracefulClose(): bool
    {
        return in_array($this->reason, [
            self::PEER_CLOSED,
            self::HALF_CLOSED,
            self::WRITE_CLOSED,
            self::READ_CLOSED
        ]);
    }

    /**
     * 判断是否需要重连
     */
    public function shouldReconnect(): bool
    {
        return $this->reason === self::CONNECTION_LOST;
    }
}