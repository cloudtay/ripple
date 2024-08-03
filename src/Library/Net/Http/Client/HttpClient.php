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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function array_shift;
use function P\async;
use function P\await;
use function P\registerForkHandler;

class HttpClient
{
    /**
     * @var array
     */
    private array $busy = [
        'ssl' => array(),
        'tcp' => array(),
    ];

    /**
     * @var array
     */
    private array $idle = [
        'ssl' => array(),
        'tcp' => array(),
    ];

    /**
     *
     */
    public function __construct()
    {
        $this->registerForkHandler();
    }

    /**
     * @param string $host
     * @param int    $port
     * @param bool   $ssl
     * @return Promise<SocketStream>
     */
    public function pullConnection(string $host, int $port, bool $ssl = false): Promise
    {
        return async(function () use (
            $ssl,
            $host,
            $port,
        ) {
            if ($ssl) {
                if(isset($this->idle['ssl']["{$host}:{$port}"])) {
                    $connection = array_shift($this->idle['ssl']["{$host}:{$port}"]);
                } else {
                    $connection = await(IO::Socket()->streamSocketClientSSL("ssl://{$host}:{$port}"));
                    $this->busy['ssl']["{$host}:{$port}"] = $connection;
                }
            } else {
                if(isset($this->idle['tcp']["{$host}:{$port}"])) {
                    $connection = array_shift($this->idle['tcp']["{$host}:{$port}"]);
                } else {
                    $connection = await(IO::Socket()->streamSocketClient("tcp://{$host}:{$port}"));
                    $this->busy['tcp']["{$host}:{$port}"] = $connection;
                }
            }
            return $connection;
        });
    }

    /**
     * @param SocketStream $stream
     * @param bool         $ssl
     * @return void
     */
    public function pushConnection(SocketStream $stream, bool $ssl): void
    {
        $this->idle[$ssl ? 'ssl' : 'tcp'][$stream->getAddress()] = $stream;
        $stream->onReadable(function (SocketStream $stream) use ($ssl) {
            unset($this->idle[$ssl ? 'ssl' : 'tcp'][$stream->getAddress()]);
            $stream->close();
        });
    }

    /**
     * @param RequestInterface $request
     * @return Promise<ResponseInterface>
     */
    public function request(RequestInterface $request): Promise
    {
        return async(function () use ($request) {
            $uri = $request->getUri();

            $method  = $request->getMethod();
            $scheme  = $uri->getScheme();
            $host    = $uri->getHost();
            $port    = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
            $path    = $uri->getPath() ?: '/';
            $address = "{$host}:$port";

            /**
             * @var SocketStream $connection
             */
            $connection = await($this->pullConnection($host, $port, $scheme === 'https'));
        });
    }

    /**
     * @return void
     */
    private function registerForkHandler(): void
    {
        registerForkHandler(function () {
            $this->registerForkHandler();
            $this->clearConnectionPool();
        });
    }

    /**
     * 清空链接池
     * @return void
     */
    private function clearConnectionPool(): void
    {
        foreach ($this->busy as $type => $connections) {
            foreach ($connections as $address => $connection) {
                $connection->close();
            }
        }

        foreach ($this->idle as $type => $connections) {
            foreach ($connections as $address => $connection) {
                $connection->close();
            }
        }
    }
}
