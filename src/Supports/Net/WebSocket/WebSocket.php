<?php declare(strict_types=1);
/*
 * Copyright (c) 2024.
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

namespace P\Net\WebSocket;


use Closure;
use Exception;
use P\IO;
use Psc\Core\Coroutine\Promise;
use Psc\Core\ModuleAbstract;
use Psc\Core\Stream\Stream;
use function P\async;
use function P\await;

class WebSocket extends ModuleAbstract
{
    /**
     * @var ModuleAbstract
     */
    protected static ModuleAbstract $instance;

    /**
     * @param string     $address
     * @param int        $timeout
     * @param mixed|null $context
     * @return Promise<Connection>
     */
    public function connect(string $address, int $timeout = 5, mixed $context = null): Promise
    {
        return async(function ($r, $d) use ($address, $timeout, $context) {
            $exploded     = explode('://', $address);
            $scheme       = $exploded[0];
            $hostExploded = explode('/', $exploded[1]);
            $hostPort     = $hostExploded[0];
            $path         = '/' . ($hostExploded[1] ?? '');

            $hostPortExploded = explode(':', $hostPort);
            $host             = $hostPortExploded[0];
            $port             = $hostPortExploded[1] ?? match ($scheme) {
                'ws'    => 80,
                'wss'   => 443,
                default => throw new Exception('Unsupported scheme')
            };

            try {
                /**
                 * @var Stream $stream
                 */
                $stream = match ($scheme) {
                    'ws'    => await(IO::Socket()->streamSocketClient("tcp://{$host}:{$port}", $timeout, $context)),
                    'wss'   => await(IO::Socket()->streamSocketClientSSL("ssl://{$host}:{$port}", $timeout, $context)),
                    default => throw new Exception('Unsupported scheme')
                };
            } catch (Exception $e) {
                $d($e);
                return;
            }

            $stream->setBlocking(false);
            $key     = base64_encode(random_bytes(16));
            $context = '';
            $context .= "GET {$path} HTTP/1.1\r\n";
            $context .= "Host: {$hostPort}\r\n";
            $context .= "Upgrade: websocket\r\n";
            $context .= "Connection: Upgrade\r\n";
            $context .= "Sec-WebSocket-Key: {$key}\r\n";
            $context .= "Sec-WebSocket-Version: 13\r\n";
            $context .= "\r\n";
            $buffer  = '';
            $stream->write($context);
            $stream->onReadable(function (Stream $stream, Closure $cancel) use (
                $r,
                $d,
                $key,
                &$buffer,
            ) {
                try {
                    $response = $stream->read(8192);
                    if ($response === '') {
                        throw new Exception('Connection closed');
                    }
                } catch (Exception $e) {
                    $stream->close();
                    $d($e);
                    return;
                }

                $buffer .= $response;
                if (str_contains($buffer, "\r\n\r\n")) {
                    $headBody = explode("\r\n\r\n", $buffer);
                    $header   = $headBody[0];
                    $body     = $headBody[1] ?? '';

                    if (!str_contains(strtolower($header), strtolower("HTTP/1.1 101 Switching Protocols"))) {
                        $stream->close();
                        $d(new Exception('Invalid response'));
                        return;
                    }

                    $headers  = [];
                    $exploded = explode("\r\n", $header);
                    foreach ($exploded as $index => $line) {
                        if ($index === 0) {
                            continue;
                        }
                        $exploded                          = explode(': ', $line);
                        $headers[strtolower($exploded[0])] = $exploded[1] ?? '';
                    }

                    if (!$signature = $headers['sec-websocket-accept'] ?? null) {
                        $stream->close();
                        $d(new Exception('Invalid response'));
                        return;
                    }

                    $expectedSignature = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

                    if ($signature !== $expectedSignature) {
                        $stream->close();
                        $d(new Exception('Invalid response'));
                        return;
                    }

                    $cancel();
                    $r(new Connection($stream));
                }
            });
        });
    }
}
