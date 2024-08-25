<?php declare(strict_types=1);

namespace Psc\Core\WebSocket;

/**
 * @Author lidongyooo
 * @Date   2024/8/25 23:07
 */
class Options
{
    public function __construct(private readonly bool $pingPong = true)
    {
    }

    /**
     * @Author lidongyooo
     * @Date   2024/8/25 23:07
     * @return bool
     */
    public function getPingPong(): bool
    {
        return $this->pingPong;
    }
}
