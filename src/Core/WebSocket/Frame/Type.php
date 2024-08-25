<?php declare(strict_types=1);

namespace Psc\Core\WebSocket\Frame;

/**
 * @Author lidongyooo
 * @Date   2024/8/25 22:57
 */
class Type
{
    public const TEXT = 0x1;

    public const BINARY = 0x2;

    public const CLOSE = 0x8;

    public const PING = 0x9;

    public const PONG = 0xa;
}
