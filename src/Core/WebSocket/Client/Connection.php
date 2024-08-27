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

namespace Psc\Core\WebSocket\Client;

use Closure;
use Exception;
use P\IO;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Exception\HandshakeException;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Core\Stream\Stream;
use Psc\Utils\Output;
use Throwable;

use function base64_encode;
use function call_user_func;
use function chr;
use function count;
use function explode;
use function ord;
use function P\async;
use function P\await;
use function pack;
use function random_bytes;
use function sha1;
use function str_contains;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unpack;

//Random\RandomException require PHP>=8.2;

/**
 * @Author cclilshy
 * @Date   下午2:28
 * 白皮书: https://datatracker.ietf.org/doc/html/rfc6455
 * 最新规范: https://websockets.spec.whatwg.org/
 */
class Connection
{
    /**
     * @var Closure
     */
    private Closure $onOpen;

    /**
     * @var Closure
     */
    private Closure $onMessage;

    /**
     * @var Closure
     */
    private Closure $onClose;

    /**
     * @var Closure
     */
    private Closure $onError;

    /**
     * @var Stream
     */
    private Stream $stream;

    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * @param string     $address
     * @param int|float  $timeout
     * @param mixed|null $context
     */
    public function __construct(
        private readonly string    $address,
        private readonly int|float $timeout = 10,
        private readonly mixed     $context = null
    ) {
        async(function () {
            try {
                await($this->_handshake());
                $this->_open();
                $this->_tick();
            } catch (Throwable $e) {
                $this->_error($e);
                if (isset($this->stream)) {
                    $this->_close();
                }
                return;
            }
            $this->stream->onReadable(function () {
                try {
                    $read         = $this->stream->read(8192);
                    $this->buffer .= $read;
                    $this->_tick();
                } catch (Throwable $e) {
                    $this->_close();
                    $this->_error($e);
                    return;
                }
            });
        });
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     * @return void
     * @throws ConnectionException
     */
    private function _tick(): void
    {
        while (strlen($this->buffer) >= 2) {
            $firstByte     = ord($this->buffer[0]);
            $fin           = ($firstByte & 0x80) === 0x80;
            $opcode        = $firstByte & 0x0f;
            $secondByte    = ord($this->buffer[1]);
            $masked        = ($secondByte & 0x80) === 0x80;
            $payloadLength = $secondByte & 0x7f;
            $offset        = 2;
            if ($payloadLength === 126) {
                if (strlen($this->buffer) < 4) {
                    return;
                }
                $payloadLength = unpack('n', substr($this->buffer, 2, 2))[1];
                $offset        += 2;
            } elseif ($payloadLength === 127) {
                if (strlen($this->buffer) < 10) {
                    return;
                }
                $payloadLength = unpack('J', substr($this->buffer, 2, 8))[1];
                $offset        += 8;
            }

            if ($masked) {
                if (strlen($this->buffer) < $offset + 4) {
                    return;
                }
                $maskingKey = substr($this->buffer, $offset, 4);
                $offset     += 4;
            }

            if (strlen($this->buffer) < $offset + $payloadLength) {
                return;
            }

            $payloadData = substr($this->buffer, $offset, $payloadLength);
            $offset      += $payloadLength;

            if ($masked) {
                $unmaskedData = '';
                for ($i = 0; $i < $payloadLength; $i++) {
                    $unmaskedData .= chr(ord($payloadData[$i]) ^ ord($maskingKey[$i % 4]));
                }
            } else {
                $unmaskedData = $payloadData;
            }

            switch ($opcode) {
                case 0x1: // 文本
                    break;
                case 0x2: // 二进制
                    break;
                case 0x8: // 关闭
                    $this->_close();
                    if (isset($this->onClose)) {
                        call_user_func($this->onClose, $this);
                    }
                    return;
                case 0x9: // ping
                    // 发送pong响应
                    $pongFrame = chr(0x8A) . chr(0x00);
                    $this->stream->write($pongFrame);
                    return;
                case 0xA: // pong
                    return;
                default:
                    break;
            }

            $this->_message($unmaskedData, $opcode);
            $this->buffer = substr($this->buffer, $offset);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     * @return Promise
     */
    private function _handshake(): Promise
    {
        return async(function ($r) {
            $exploded = explode('://', $this->address);

            if (count($exploded) !== 2) {
                throw new Exception('Invalid address');
            }

            $scheme           = $exploded[0];
            $hostExploded     = explode('/', $exploded[1]);
            $hostPort         = $hostExploded[0];
            $hostPortExploded = explode(':', $hostPort);
            $host             = $hostPortExploded[0];
            $port             = $hostPortExploded[1] ?? match ($scheme) {
                'ws' => 80,
                'wss' => 443,
                default => throw new Exception('Unsupported scheme')
            };

            $path = $hostExploded[1] ?? '';
            $path = "/{$path}";

            $this->stream = match ($scheme) {
                'ws' => await(IO::Socket()->streamSocketClient("tcp://{$host}:{$port}", $this->timeout, $this->context)),
                'wss' => await(IO::Socket()->streamSocketClientSSL("ssl://{$host}:{$port}", $this->timeout, $this->context)),
                default => throw new Exception('Unsupported scheme')
            };

            $this->stream->onClose(fn () => $this->_close());
            $this->stream->setBlocking(false);

            $key     = base64_encode(random_bytes(16));
            $context = "GET {$path} HTTP/1.1\r\n";
            $context .= "Host: {$hostPort}\r\n";
            $context .= "Upgrade: websocket\r\n";
            $context .= "Connection: Upgrade\r\n";
            $context .= "Sec-WebSocket-Key: {$key}\r\n";
            $context .= "Sec-WebSocket-Version: 13\r\n";
            $context .= "\r\n";

            $buffer = '';

            $this->stream->write($context);
            $this->stream->onReadable(function (Stream $stream, Closure $cancel) use (
                $r,
                $key,
                &$buffer,
            ) {
                $response = $this->stream->read(8192);
                if ($response === '') {
                    throw new HandshakeException('Connection closed');
                }

                $buffer .= $response;

                if (str_contains($buffer, "\r\n\r\n")) {
                    $headBody = explode("\r\n\r\n", $buffer);
                    $header   = $headBody[0];
                    $body     = $headBody[1] ?? '';

                    if (!str_contains(
                        strtolower($header),
                        strtolower("HTTP/1.1 101 Switching Protocols")
                    )) {
                        throw new HandshakeException('Invalid response');
                    }

                    $headers  = array();
                    $exploded = explode("\r\n", $header);

                    foreach ($exploded as $index => $line) {
                        if ($index === 0) {
                            continue;
                        }
                        $exploded                          = explode(': ', $line);
                        $headers[strtolower($exploded[0])] = $exploded[1] ?? '';
                    }

                    if (!$signature = $headers['sec-websocket-accept'] ?? null) {
                        throw new Exception('Invalid response');
                    }

                    $expectedSignature = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                    if (trim($signature) !== $expectedSignature) {
                        throw new Exception('Invalid response');
                    }

                    $cancel();
                    $r();
                }
            });
        });
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     * @return void
     */
    private function _close(): void
    {
        if (isset($this->stream)) {
            $this->stream->close();

            if (isset($this->onClose)) {
                try {
                    call_user_func($this->onClose, $this);
                } catch (Throwable $e) {
                    Output::error($e->getMessage());
                }
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     * @return void
     */
    private function _open(): void
    {
        if (isset($this->onOpen)) {
            try {
                call_user_func($this->onOpen, $this);
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @param Throwable $e
     * @return void
     */
    private function _error(Throwable $e): void
    {
        if (isset($this->onError)) {
            try {
                call_user_func($this->onError, $e, $this);
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @param string $unmaskedData
     * @param int    $opcode
     * @return void
     */
    private function _message(string $unmaskedData, int $opcode): void
    {
        if (isset($this->onMessage)) {
            try {
                call_user_func($this->onMessage, $unmaskedData, $this);
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @param string $data
     * @return void
     * @throws ConnectionException
     * @throws Throwable
     */
    public function send(string $data): void
    {
        $finOpcode = 0x81;
        $packet    = chr($finOpcode);
        $length    = strlen($data);

        if ($length <= 125) {
            $packet .= chr($length | 0x80);
        } elseif ($length < 65536) {
            $packet .= chr(126 | 0x80);
            $packet .= pack('n', $length);
        } else {
            $packet .= chr(127 | 0x80);
            $packet .= pack('J', $length);
        }

        $maskingKey = random_bytes(4);
        $packet     .= $maskingKey;
        $maskedData = '';
        for ($i = 0; $i < $length; $i++) {
            $maskedData .= chr(ord($data[$i]) ^ ord($maskingKey[$i % 4]));
        }
        $packet .= $maskedData;
        $this->stream->write($packet);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @param Closure $onOpen
     * @return void
     */
    public function onConnect(Closure $onOpen): void
    {
        $this->onOpen($onOpen);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @param Closure $onMessage
     * @return void
     */
    public function onMessage(Closure $onMessage): void
    {
        $this->onMessage = $onMessage;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @param Closure $onClose
     * @return void
     */
    public function onClose(Closure $onClose): void
    {
        $this->onClose = $onClose;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @param Closure $onError
     * @return void
     */
    public function onError(Closure $onError): void
    {
        $this->onError = $onError;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @param Closure $onOpen
     * @return void
     */
    public function onOpen(Closure $onOpen): void
    {
        $this->onOpen = $onOpen;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:47
     * @return void
     */
    public function close(): void
    {
        $this->_close();
    }
}
