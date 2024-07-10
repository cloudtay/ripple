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
use Psc\Core\Stream\Stream;
use Throwable;

class Connection
{
    public Closure $onOpen;
    public Closure $onMessage;
    public Closure $onClose;
    public Closure $onError;

    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * @param Stream $stream
     */
    public function __construct(private readonly Stream $stream)
    {
        $this->stream->onClose(function () {
            if (isset($this->onClose)) {
                call_user_func($this->onClose, $this);
            }
        });

        $this->stream->onReadable(function (Stream $stream) {
            try {
                $read = $stream->read(8192);
            } catch (Throwable $e) {
                $stream->close();
                return;
            }
            $this->buffer .= $read;
            $this->tick();
        });

        if (isset($this->onOpen)) {
            call_user_func($this->onOpen, $this);
        }
    }

    /**
     * @return void
     */
    private function tick(): void
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
            $this->handleMessage($opcode, $unmaskedData);
            $this->buffer = substr($this->buffer, $offset);
        }
    }

    /**
     * @param int    $opcode
     * @param string $data
     * @return void
     */
    private function handleMessage(int $opcode, string $data): void
    {
        switch ($opcode) {
            case 0x1: // 文本
                break;
            case 0x2: // 二进制
                break;
            case 0x8: // 关闭
                $this->stream->close();
                return;
            case 0x9: // ping
                $this->sendPong();
                return;
            case 0xA: // pong
                return;
            default:
                break;
        }

        if (isset($this->onMessage)) {
            call_user_func($this->onMessage, $data, $this);
        }
    }

    /**
     * @return void
     */
    private function sendPong(): void
    {
        $pongFrame = chr(0x8A) . chr(0x00);
        $this->stream->write($pongFrame);
    }

    /**
     * @param string $data
     * @return void
     */
    public function send(string $data): void
    {
        try {
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
        } catch (Throwable $e) {
            $this->stream->close();
            if (isset($this->onError)) {
                call_user_func($this->onError, $e, $this);
            }
        }

    }
}
