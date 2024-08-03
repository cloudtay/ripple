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

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use P\IO;
use Psc\Core\Stream\SocketStream;
use Psr\Http\Message\RequestInterface;

use function P\await;

include_once __DIR__ . '/../vendor/autoload.php';

$handler = new class () {
    /**
     * @param RequestInterface $request
     * @return PromiseInterface
     */
    public function __invoke(RequestInterface $request): PromiseInterface
    {
        $promise = new Promise(function () use ($request, &$promise) {
            $promise->resolve(new Response(200, [], 'Hello, World!'));
            \P\async(function () use ($request, $promise) {
                $uri = $request->getUri();

                $method  = $request->getMethod();
                $scheme  = $uri->getScheme();
                $host    = $uri->getHost();
                $port    = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
                $path    = $uri->getPath() ?: '/';
                $address = "{$host}:$port";

                /**
                 * @var SocketStream $stream
                 */
                $stream = match ($uri->getScheme()) {
                    'http' => await(IO::Socket()->streamSocketClient("tcp://{$address}")),
                    'https' => await(IO::Socket()->streamSocketClientSSL("ssl://{$address}")),
                    default => throw new \RuntimeException('Unsupported scheme: ' . $uri->getScheme()),
                };

                //构建请求报文
                $content = "{$method} {$path} HTTP/1.1\r\n";
                $content .= "Host: {$address}\r\n";
                foreach ($request->getHeaders() as $name => $values) {
                    $content .= "{$name}: " . \implode(', ', $values) . "\r\n";
                }
                $content .= "\r\n";
                $content .= $request->getBody()->getContents();

                $responseBuffer = '';
                $responseHeader = '';
                $responseBody = '';
                $responseHeaderArray = [];
                $step = 0;
                $carry = 0;
                $contentLength = 0;

                $stream->write($content);
                $stream->onReadable(function (SocketStream $stream) use (
                    &$responseBuffer,
                    &$responseHeader,
                    &$responseBody,
                    &$responseHeaderArray,
                    &$step,
                    &$carry,
                    &$contentLength
                ) {
                    $responseBuffer .= $stream->read(1024);
                    if($step === 0) {
                        if(\str_contains($responseBuffer, "\r\n\r\n")) {
                            [$responseHeader, $responseBody] = \explode("\r\n\r\n", $responseBuffer, 2);
                            $responseHeaderArray = \explode("\r\n", $responseHeader);
                            if(!$contentLength = \array_reduce($responseHeaderArray, function ($carry, $item) {
                                if(\str_starts_with($item, 'Content-Length: ')) {
                                    $carry = (int)\substr($item, 16);
                                }
                                return $carry;
                            })) {
                                $step = 2;
                            } else {
                                $step = 1;
                            }
                        }
                    }
                    if($step === 2) {
                        $stream->close();
                    }
                });
            });

        });

        \P\defer(function () use ($promise) {
            $promise->wait();
        });

        return $promise;
    }
};


$client = new Client(['handler' => $handler]);
$client->getAsync('https://www.baidu.com')->then(function (Response $response) {
    \var_dump($response->getStatusCode());
}, function ($e) {
    \var_dump($e->getMessage());
    die;
});

\P\run();
