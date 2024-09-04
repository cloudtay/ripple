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

use Closure;
use Co\IO;
use GuzzleHttp\Psr7\MultipartStream;
use InvalidArgumentException;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Socket\SocketStream;
use Psc\Core\Socket\Tunnel\ProxyHttp;
use Psc\Core\Socket\Tunnel\ProxySocks5;
use Psc\Core\Stream\Exception\ConnectionException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Co\await;
use function Co\cancel;
use function Co\delay;
use function Co\repeat;
use function fclose;
use function fopen;
use function implode;
use function in_array;
use function is_resource;
use function parse_url;
use function str_contains;
use function strtolower;

class HttpClient
{
    /*** @var ConnectionPool */
    private ConnectionPool $connectionPool;

    /*** @var bool */
    private bool $pool;

    /*** @param array $config */
    public function __construct(private readonly array $config = [])
    {
        $pool = $this->config['pool'] ?? 'off';
        $this->pool = in_array($pool, [true, 1, 'on'], true);

        if ($this->pool) {
            $this->connectionPool = new ConnectionPool();
        }
    }

    /**
     * @param RequestInterface $request
     * @param array            $option
     * @return Promise<ResponseInterface>
     */
    public function request(RequestInterface $request, array $option = []): Promise
    {
        return \Co\promise(function (Closure $r, Closure $d, Promise $promise) use ($request, $option) {
            $uri = $request->getUri();

            $method = $request->getMethod();
            $scheme = $uri->getScheme();
            $host   = $uri->getHost();
            $port   = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
            $path   = $uri->getPath() ?: '/';
            $query  = $uri->getQuery();
            $query  = $query ? "?{$query}" : '';

            $connection = $this->pullConnection(
                $host,
                $port,
                $scheme === 'https',
                $option['timeout'] ?? 0,
                $option['proxy'] ?? null
            );

            $writeHandler = fn (string|false $content) => $connection->stream->write($content);
            $tickHandler  = fn (string|false $content) => $connection->tick($content);

            if ($captureWrite = $option['capture_write'] ?? null) {
                $writeHandler = fn (string|false $content) => $captureWrite($content, $writeHandler);
            }

            if ($captureRead = $option['capture_read'] ?? null) {
                $tickHandler = fn (string|false $content) => $captureRead($content, $tickHandler);
            }

            $header = "{$method} {$path}{$query} HTTP/1.1\r\n";
            foreach ($request->getHeaders() as $name => $values) {
                $header .= "{$name}: " . implode(', ', $values) . "\r\n";
            }

            //写入初始化头
            $writeHandler($header);
            if ($bodyStream = $request->getBody()) {
                if (!$request->getHeader('Content-Length')) {
                    $size = $bodyStream->getSize();
                    $size > 0 && $writeHandler("Content-Length: {$bodyStream->getSize()}\r\n");
                }

                if ($bodyStream->getMetadata('uri') === 'php://temp') {
                    $writeHandler("\r\n");
                    if ($bodyContent = $bodyStream->getContents()) {
                        $writeHandler($bodyContent);
                    }
                } elseif ($bodyStream instanceof MultipartStream) {
                    if (!$request->getHeader('Content-Type')) {
                        $writeHandler("Content-Type: multipart/form-data; boundary={$bodyStream->getBoundary()}\r\n");
                    }
                    $writeHandler("\r\n");
                    repeat(static function (Closure $cancel) use ($connection, $bodyStream, $r, $d, $writeHandler) {
                        try {
                            $content = '';
                            while ($buffer = $bodyStream->read(8192)) {
                                $content .= $buffer;
                            }

                            if ($content) {
                                $writeHandler($content);
                            } else {
                                $cancel();
                                $bodyStream->close();
                            }
                        } catch (Throwable) {
                            $cancel();
                            $bodyStream->close();
                            $d(new InvalidArgumentException('Invalid body stream'));
                        }
                    }, 0.1);
                } else {
                    throw new InvalidArgumentException('Invalid body stream');
                }
            } else {
                $writeHandler("\r\n");
            }

            if ($timeout = $option['timeout'] ?? null) {
                $delay = delay(static function () use ($connection, $d) {
                    $connection->stream->close();
                    $d(new InvalidArgumentException('Request timeout'));
                }, $timeout);
                $promise->finally(static function () use ($delay) {
                    cancel($delay);
                });
            }

            if ($sink = $option['sink'] ?? null) {
                $connection->setOutput($sinkFile = fopen($sink, 'wb'));
                $promise->finally(static function () use ($sinkFile) {
                    if (is_resource($sinkFile)) {
                        fclose($sinkFile);
                    }
                });
            }

            /**
             * 解析响应过程
             */
            $connection->stream->onReadable(function (SocketStream $socketStream, Closure $cancel) use (
                $host,
                $port,
                $connection,
                $scheme,
                $r,
                $d,
                $tickHandler
            ) {
                try {
                    $content = '';
                    while ($buffer = $socketStream->read(8192)) {
                        $content .= $buffer;
                    }

                    if ($content === '') {
                        if (!$socketStream->eof()) {
                            return;
                        }
                        $response = $tickHandler(false);
                    } else {
                        $response = $tickHandler($content);
                    }

                    if ($response) {
                        $k = implode(', ', $response->getHeader('Connection'));
                        if (str_contains(strtolower($k), 'keep-alive') && $this->pool) {
                            /**
                             * 推入连接池
                             */
                            $this->pushConnection(
                                $connection,
                                ConnectionPool::generateConnectionKey($host, $port)
                            );
                            $cancel();
                        } else {
                            $socketStream->close();
                        }
                        $r($response);
                    }


                } catch (Throwable $exception) {
                    $socketStream->close();
                    $d($exception);
                    return;
                }
            });
        });
    }

    /**
     * @param string      $host
     * @param int         $port
     * @param bool        $ssl
     * @param int         $timeout
     * @param string|null $tunnel
     * @return Connection
     * @throws ConnectionException
     * @throws Throwable
     */
    private function pullConnection(string $host, int $port, bool $ssl, int $timeout = 0, string|null $tunnel = null): Connection
    {
        if ($this->pool) {
            $connection = $this->connectionPool->pullConnection($host, $port, $ssl, $timeout, $tunnel);
        } else {
            if ($tunnel) {
                $parse = parse_url($tunnel);
                if (!isset($parse['host'], $parse['port'])) {
                    throw new ConnectionException('Invalid proxy address');
                }
                $payload = [
                    'host' => $host,
                    'port' => $port,
                ];
                if (isset($parse['user'], $parse['pass'])) {
                    $payload['username'] = $parse['user'];
                    $payload['password'] = $parse['pass'];
                }

                switch ($parse['scheme']) {
                    case 'socks':
                    case 'socks5':
                        $tunnelSocket = ProxySocks5::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocketStream();
                        $ssl && await(IO::Socket()->streamEnableCrypto($tunnelSocket));
                        $connection = new Connection($tunnelSocket);
                        break;
                    case 'http':
                        $tunnelSocket = ProxyHttp::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocketStream();
                        $ssl && await(IO::Socket()->streamEnableCrypto($tunnelSocket));
                        $connection = new Connection($tunnelSocket);
                        break;
                    case 'https':
                        $tunnelSocket = ProxyHttp::connect("tcp://{$parse['host']}:{$parse['port']}", $payload, true)->getSocketStream();
                        $ssl && await(IO::Socket()->streamEnableCrypto($tunnelSocket));
                        $connection = new Connection($tunnelSocket);
                        break;
                    default:
                        throw new ConnectionException('Unsupported proxy protocol');
                }
            } else {
                $connection =  $ssl
                    ? new Connection(await(IO::Socket()->streamSocketClientSSL("ssl://{$host}:{$port}", $timeout)))
                    : new Connection(await(IO::Socket()->streamSocketClient("tcp://{$host}:{$port}", $timeout)));
            }
        }

        $connection->stream->setBlocking(false);
        return $connection;
    }

    /**
     * @param Connection $connection
     * @param string     $key
     * @return void
     */
    private function pushConnection(Connection $connection, string $key): void
    {
        if ($this->pool) {
            $this->connectionPool->pushConnection($connection, $key);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/31 14:32
     * @return ConnectionPool
     */
    public function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool;
    }
}
