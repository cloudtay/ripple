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

namespace Psc\Library\Net\Http\Client;

use P\IO;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Stream\SocketStream;
use Psc\Std\Stream\Exception\ConnectionException;
use Throwable;

use function array_pop;
use function P\async;
use function P\await;
use function P\cancel;
use function P\cancelForkHandler;
use function P\registerForkHandler;

class ConnectionPool
{
    /**
     * @var array
     */
    private array $busySSL = [];
    private array $busyTCP = [];
    private array $idleSSL = [];
    private array $idleTCP = [];
    private array $listenEventMap = [];
    private int $forkEventId;


    public function __construct()
    {
        $this->registerForkHandler();
    }

    public function __destruct()
    {
        $this->clearConnectionPool();
        cancelForkHandler($this->forkEventId);
    }

    /**
     * @param string $host
     * @param int    $port
     * @param bool   $ssl
     * @return Promise<Connection>
     */
    public function pullConnection(string $host, int $port, bool $ssl = false): Promise
    {
        return async(function () use (
            $ssl,
            $host,
            $port,
        ) {
            $key = "tcp://{$host}:{$port}";
            if ($ssl) {
                if (!isset($this->idleSSL[$key]) || empty($this->idleSSL[$key])) {
                    $connection = new Connection(await(IO::Socket()->streamSocketClientSSL("ssl://{$host}:{$port}")));
                    $this->pushConnection($connection, $ssl);
                } else {
                    /**
                     * @var Connection $connection
                     */
                    $connection = array_pop($this->idleSSL[$key]);
                    cancel($this->listenEventMap[$connection->stream->id]);
                    unset($this->listenEventMap[$connection->stream->id]);
                    return $connection;
                }
            } else {
                if (!isset($this->idleTCP[$key]) || empty($this->idleTCP[$key])) {
                    $connection = new Connection(await(IO::Socket()->streamSocketClient("tcp://{$host}:{$port}")));
                    $this->pushConnection($connection, $ssl);
                } else {
                    $connection = array_pop($this->idleTCP[$key]);
                    cancel($this->listenEventMap[$connection->stream->id]);
                    unset($this->listenEventMap[$connection->stream->id]);
                    return $connection;
                }
            }
            return await($this->pullConnection($host, $port, $ssl));
        });
    }

    /**
     * @param Connection $connection
     * @param bool         $ssl
     * @return void
     */
    public function pushConnection(Connection $connection, bool $ssl): void
    {
        $key = "{$connection->stream->getAddress()}";
        if ($ssl) {
            if (!isset($this->idleSSL[$key])) {
                $this->idleSSL[$key] = [];
            }
            $this->idleSSL[$key][$connection->stream->id] = $connection;

            /**
             *
             */
            $this->listenEventMap[$connection->stream->id] = $connection->stream->onReadable(function (SocketStream $stream) use ($key, $connection) {
                try {
                    if($stream->read(1) === '') {
                        throw new ConnectionException('Connection closed by peer');
                    }
                } catch (Throwable) {
                    if (isset($this->idleSSL[$key])) {
                        unset($this->idleSSL[$key][$connection->stream->id]);
                        if (empty($this->idleSSL[$key])) {
                            unset($this->idleSSL[$key]);
                        }
                    }
                    $stream->close();
                }
            });
        } else {
            if (!isset($this->idleTCP[$key])) {
                $this->idleTCP[$key] = [];
            }
            $this->idleTCP[$key][$connection->stream->id] = $connection;

            if (isset($this->busyTCP[$key])) {
                unset($this->busyTCP[$key]);
            }

            $this->listenEventMap[$connection->stream->id] = $connection->stream->onReadable(function (SocketStream $stream) use ($key, $connection) {
                if (isset($this->idleTCP[$key])) {
                    unset($this->idleTCP[$key][$connection->stream->id]);
                    if (empty($this->idleTCP[$key])) {
                        unset($this->idleTCP[$key]);
                    }
                }
                $stream->close();
            });
        }
    }

    /**
     * @return void
     */
    private function registerForkHandler(): void
    {
        $this->forkEventId = registerForkHandler(function () {
            $this->registerForkHandler();
            $this->clearConnectionPool();
        });
    }

    /**
     * 通过关闭所有空闲和繁忙的连接来清除连接池。
     * @return void
     */
    private function clearConnectionPool(): void
    {
        $closeConnections = function (&$pool) {
            foreach ($pool as $key => $connections) {
                foreach ($connections as $connection) {
                    $connection->stream->close();
                }
                unset($pool[$key]);
            }
        };

        // Clear and close all SSL connections
        $closeConnections($this->idleSSL);
        $closeConnections($this->busySSL);

        // Clear and close all TCP connections
        $closeConnections($this->idleTCP);
        $closeConnections($this->busyTCP);
    }
}
