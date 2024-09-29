<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Psc\Core\Socket\Tunnel;

use Co\IO;
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Exception\ConnectionException;
use Throwable;

use function is_string;
use function stream_context_create;
use function stream_context_set_option;

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
     * @param SocketStream $proxy
     * @param array        $payload
     */
    public function __construct(protected SocketStream $proxy, protected array $payload)
    {
        $this->proxy->setBlocking(false);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 12:38
     *
     * @param SocketStream|string $target
     * @param array               $payload
     * @param bool                $ssl
     * @param bool                $wait
     *
     * @return static
     * @throws ConnectionException
     */
    public static function connect(SocketStream|string $target, array $payload, bool $ssl = false, bool $wait = true): static
    {
        if (is_string($target)) {
            $context = stream_context_create();
            stream_context_set_option($context, 'ssl', 'verify_peer', false);
            stream_context_set_option($context, 'ssl', 'verify_peer_name', false);
            $target = IO::Socket()->connect($target, 0, $context);
            $tunnel = new static($target, $payload);
            if ($wait) {
                $tunnel->handshake();
                if ($ssl) {
                    $target->enableSSL();
                }
            }
            return $tunnel;
        }

        $tunnel = new static($target, $payload);
        $wait && $tunnel->handshake();
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
     * @return SocketStream
     */
    public function getSocketStream(): SocketStream
    {
        return $this->proxy;
    }
}
