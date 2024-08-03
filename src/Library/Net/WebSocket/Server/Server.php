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

namespace Psc\Library\Net\WebSocket\Server;

use Closure;
use P\IO;
use Psc\Core\Stream\SocketStream;
use Psc\Std\Stream\Exception\RuntimeException;
use Throwable;

use function count;
use function explode;
use function P\async;
use function P\await;

use const SO_KEEPALIVE;
use const SO_RCVBUF;
use const SO_REUSEADDR;
use const SO_REUSEPORT;
use const SO_SNDBUF;
use const SOL_SOCKET;
use const SOL_TCP;
use const TCP_NODELAY;

/**
 * [协议相关]
 * 白皮书: https://datatracker.ietf.org/doc/html/rfc6455
 * 最新规范: https://websockets.spec.whatwg.org/
 */
class Server
{
    /**
     * @var Closure(string $data, Connection $connection):void
     */
    private Closure $onMessage;

    /**
     * @var Closure(Connection $connection):void
     */
    private Closure $onConnect;

    /**
     * @var Closure(Connection $connection):void
     */
    private Closure $onClose;

    /**
     * @var SocketStream
     */
    private SocketStream $server;

    /**
     * @param string     $address
     * @param mixed|null $context
     */
    public function __construct(string $address, mixed $context = null)
    {
        async(function () use ($address, $context) {
            $addressExploded = explode('://', $address);
            if (count($addressExploded) !== 2) {
                throw new RuntimeException('Address format error');
            }

            $scheme = $addressExploded[0];
            $tcpAddress = $addressExploded[1];
            $tcpAddressExploded = explode(':', $tcpAddress);
            $host = $tcpAddressExploded[0];
            $port = $tcpAddressExploded[1] ?? 80;

            $this->server = await(IO::Socket()->streamSocketServer("tcp://{$host}:{$port}", $context));

            $this->server->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
            $this->server->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
            $this->server->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
            $this->server->setBlocking(false);
        });
    }

    /**
     * @var Connection[]
     */
    private array $client2connection = array();

    /**
     * @param string     $data
     * @param Connection $connection
     * @return void
     */
    private function _onMessage(string $data, Connection $connection): void
    {
        if (isset($this->onMessage)) {
            ($this->onMessage)($data, $connection);
        }
    }

    /**
     * @param Connection $connection
     * @return void
     */
    private function _onConnect(Connection $connection): void
    {
        if (isset($this->onConnect)) {
            ($this->onConnect)($connection);
        }
    }

    /**
     * @param Connection $connection
     * @return void
     */
    private function _onClose(Connection $connection): void
    {
        if (isset($this->onClose)) {
            ($this->onClose)($connection);
        }

        unset($this->client2connection[$connection->getId()]);
    }

    /**
     * @return void
     */
    public function listen(): void
    {
        $this->server->onReadable(function (SocketStream $stream) {
            try {
                $client = $stream->accept();

                $client->setBlocking(false);
                $client->setOption(SOL_SOCKET, SO_RCVBUF, 256000);
                $client->setOption(SOL_SOCKET, SO_SNDBUF, 256000);
                $client->setOption(SOL_TCP, TCP_NODELAY, 1);
                $connection = $this->client2connection[$stream->id] = new Connection($client);

                $connection->onMessage(fn (string $data, Connection $connection) => $this->_onMessage($data, $connection));
                $connection->onConnect(fn (Connection $connection) => $this->_onConnect($connection));
                $connection->onClose(fn (Connection $connection) => $this->_onClose($connection));
            } catch (Throwable) {
                return;
            }
        });
    }

    /**
     * Broadcast a message and return the number of clients successfully sent
     * @param string $data messageContent
     * @return int Number of clients sent successfully
     */
    public function broadcast(string $data): int
    {
        $count = 0;
        foreach ($this->getConnections() as $connection) {
            if(!$connection->isHandshake()) {
                continue;
            }

            try {
                $connection->send($data);
                $count++;
            } catch (Throwable) {
                $connection->close();
            }
        }
        return $count;
    }

    /**
     * @return Connection[]
     */
    public function getConnections(): array
    {
        return $this->client2connection;
    }

    /**
     * @param Closure $onMessage
     * @return void
     */
    public function onMessage(Closure $onMessage): void
    {
        $this->onMessage = $onMessage;
    }

    /**
     * @param Closure $onConnect
     * @return void
     */
    public function onConnect(Closure $onConnect): void
    {
        $this->onConnect = $onConnect;
    }

    /**
     * @param Closure $onClose
     * @return void
     */
    public function onClose(Closure $onClose): void
    {
        $this->onClose = $onClose;
    }
}
