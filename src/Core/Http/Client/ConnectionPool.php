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

namespace Psc\Core\Http\Client;

use Co\IO;
use Psc\Core\Socket\Proxy\ProxyHttp;
use Psc\Core\Socket\Proxy\ProxySocks5;
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Exception\ConnectionException;
use Throwable;

use function array_pop;
use function Co\cancel;
use function Co\cancelForkHandler;
use function Co\registerForkHandler;
use function parse_url;

class ConnectionPool
{
    /*** @var array */
    private array $idleConnections = [];

    /*** @var array */
    private array $listenEventMap = [];

    /*** @var int */
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
     * @Author cclilshy
     * @Date   2024/8/29 23:18
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
     * @param string      $host
     * @param int         $port
     * @param bool        $ssl
     * @param int|float   $timeout
     * @param string|null $proxy http://username:password@proxy.example.com:8080
     * @return Connection
     * @throws ConnectionException
     * @throws Throwable
     */
    public function pullConnection(
        string $host,
        int $port,
        bool $ssl = false,
        int|float $timeout = 0,
        string|null $proxy = null,
    ): Connection {
        $key = ConnectionPool::generateConnectionKey($host, $port);
        if (!isset($this->idleConnections[$key]) || empty($this->idleConnections[$key])) {
            // 连接创建逻辑
            return $this->createConnection($host, $port, $ssl, $timeout, $proxy);
        } else {
            /**
             * @var Connection $connection
             */
            $connection = array_pop($this->idleConnections[$key]);
            if (empty($this->idleConnections[$key])) {
                unset($this->idleConnections[$key]);
            }

            cancel($this->listenEventMap[$connection->stream->id]);
            unset($this->listenEventMap[$connection->stream->id]);
            return $connection;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @param string      $host
     * @param int         $port
     * @param bool        $ssl
     * @param int|float   $timeout
     * @param string|null $proxy
     * @return Connection
     * @throws ConnectionException
     * @throws Throwable
     */
    private function createConnection(string $host, int $port, bool $ssl, int|float $timeout, string|null $proxy): Connection
    {
        if ($proxy) {
            $parse = parse_url($proxy);
            if (!isset($parse['host'], $parse['port'])) {
                throw new ConnectionException('Invalid proxy address');
            }
            $payload = ['host' => $host, 'port' => $port];
            if (isset($parse['user'], $parse['pass'])) {
                $payload['username'] = $parse['user'];
                $payload['password'] = $parse['pass'];
            }
            $proxySocketStream = $this->createProxySocketStream($parse, $payload);
            $ssl && IO::Socket()->streamEnableCrypto($proxySocketStream)->await();
            return new Connection($proxySocketStream);
        }

        $stream = IO::Socket()->streamSocketClient("tcp://{$host}:{$port}", $timeout)->await();
        $ssl && IO::Socket()->streamEnableCrypto($stream)->await();
        return new Connection($stream);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @param array $parse
     * @param array $payload
     * @return SocketStream
     * @throws ConnectionException
     * @throws Throwable
     */
    private function createProxySocketStream(array $parse, array $payload): SocketStream
    {
        switch ($parse['scheme']) {
            case 'socks':
            case 'socks5':
                return ProxySocks5::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocketStream();
            case 'http':
            case 'https':
                $secure = $parse['scheme'] === 'https';
                return ProxyHttp::connect("tcp://{$parse['host']}:{$parse['port']}", $payload, $secure)->getSocketStream();
            default:
                throw new ConnectionException('Unsupported proxy protocol');
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @param string     $key
     * @param Connection $connection
     * @return void
     */
    private function removeConnection(string $key, Connection $connection): void
    {
        $streamId = $connection->stream->id;
        unset($this->idleConnections[$key][$streamId]);
        if (empty($this->idleConnections[$key])) {
            unset($this->idleConnections[$key]);
        }
        if (isset($this->listenEventMap[$streamId])) {
            cancel($this->listenEventMap[$streamId]);
            unset($this->listenEventMap[$streamId]);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @param string|null $key
     * @return void
     */
    public function clearConnectionPool(string|null $key = null): void
    {
        if ($key) {
            if (!isset($this->idleConnections[$key])) {
                return;
            }
            foreach ($this->idleConnections[$key] as $connection) {
                $connection->stream->close();
            }
            unset($this->idleConnections[$key]);
            return;
        }

        foreach ($this->idleConnections as $keyI => $connections) {
            foreach ($connections as $keyK => $connection) {
                $connection->stream->close();
                unset($this->idleConnections[$keyI][$keyK]);
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @param Connection $connection
     * @param string     $key
     * @return void
     */
    public function pushConnection(Connection $connection, string $key): void
    {
        $streamId = $connection->stream->id;
        if (isset($this->listenEventMap[$streamId])) {
            cancel($this->listenEventMap[$streamId]);
            unset($this->listenEventMap[$streamId]);
        }
        $this->idleConnections[$key][$streamId] = $connection;
        $this->listenEventMap[$streamId]        = $connection->stream->onReadable(function (SocketStream $stream) use ($key, $connection) {
            try {
                if ($stream->read(1) === '' && $stream->eof()) {
                    throw new ConnectionException('Connection closed by peer');
                }
            } catch (Throwable) {
                $this->removeConnection($key, $connection);
                $stream->close();
            }
        });
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 09:43
     * @param string $host
     * @param int    $port
     * @return string
     */
    public static function generateConnectionKey(string $host, int $port): string
    {
        return  "{$host}:{$port}";
    }
}
