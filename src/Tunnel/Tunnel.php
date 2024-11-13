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

namespace Ripple\Tunnel;

use Ripple\Socket;

use function is_string;
use function str_starts_with;
use function stream_context_create;

/**
 * This standard applies to all proxies in transparent transmission mode. The socket created by this method can be directly accessed as the target socket.
 * It should be noted that the url part of getMeta cannot be expressed as a target address, and users should do the mapping manually.
 *
 * @Author cclilshy
 * @Date   2024/8/29 11:28
 */
abstract class Tunnel
{
    /**
     * @param Socket $proxy
     * @param array        $payload
     */
    public function __construct(protected Socket $proxy, protected array $payload)
    {
        $this->proxy->setBlocking(false);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 12:38
     *
     * @param Socket|string $target
     * @param array               $payload
     * @param bool                $wait
     *
     * @return static
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    public static function connect(Socket|string $target, array $payload, bool $wait = true): Tunnel
    {
        if (is_string($target)) {
            $context = stream_context_create([
                'ssl' => [
                    'peer_name'         => $payload['host'],
                    'allow_self_signed' => true
                ]
            ]);

            $target = match (str_starts_with($target, 'ssl://')) {
                true    => Socket::connectWithSSL($target, 0, $context),
                default => Socket::connect($target, 0, $context)
            };
        }

        $tunnel = new static($target, $payload);
        if ($wait) {
            $tunnel->handshake();
        }
        return $tunnel;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 11:34
     * @return void
     */
    abstract public function handshake(): void;

    /**
     * @Author cclilshy
     * @Date   2024/8/29 12:33
     * @return Socket
     */
    public function getSocket(): Socket
    {
        return $this->proxy;
    }
}
