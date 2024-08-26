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

namespace Psc\Core\WebSocket\Server;

use Closure;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Core\Stream\Stream;
use Psc\Core\WebSocket\Frame\Type;
use Throwable;
use function call_user_func;
use function chr;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * @Author cclilshy
 * @Date   2024/8/15 14:44
 */
class Connection
{
    /**
     * @var string
     */
    public string $buffer = '';

    /**
     * @var string
     */
    private string $headerContent = '';

    /**
     * @var int
     */
    private int $step = 0;

    /**
     * @var Closure|null
     */
    private Closure|null $onMessage = null;

    /**
     * @var Closure|null
     */
    private Closure|null $onConnect = null;

    /**
     * @var Closure|null
     */
    private Closure|null $onClose = null;

    /**
     * @param Stream $stream
     * @param Server $server
     */
    public function __construct(public readonly Stream $stream, private readonly Server $server)
    {
        $this->stream->onReadable(fn (Stream $stream) => $this->handleRead($stream));
        $this->stream->onClose(fn () => $this->close());
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:44
     * @param Stream $stream
     * @return void
     */
    private function handleRead(Stream $stream): void
    {
        try {
            $data = $stream->read(8192);
            if ($data === '') {
                throw new ConnectionException();
            }
            $this->push($data);
        } catch (Throwable) {
            $this->close();
            return;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @param string $data
     * @return void
     * @throws ConnectionException
     */
    public function push(string $data): void
    {
        $this->buffer .= $data;

        if ($this->step === 0) {
            $handshake = Handshake::accept($this);

            if($handshake === null) {
                return;
            } elseif ($handshake === false) {
                throw new ConnectionException('Handshake failed');
            } else {
                $this->step = 1;
                if ($this->onConnect !== null) {
                    call_user_func($this->onConnect, $this);
                }

                foreach ($this->parse() as $message) {
                    if ($this->onMessage !== null) {
                        call_user_func($this->onMessage, $message, $this);
                    }
                }
            }
        } else {
            foreach ($this->parse() as $message) {
                if ($this->onMessage !== null) {
                    call_user_func($this->onMessage, $message, $this);
                }
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @param string $message
     * @return bool
     */
    public function send(string $message): bool
    {
        try {
            if (!$this->isHandshake()) {
                throw new ConnectionException('Connection is not established yet');
            }
            $this->stream->write($this->build($message));
        } catch (ConnectionException) {
            $this->close();
            return false;
        }
        return true;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @return void
     */
    public function close(): void
    {
        $this->stream->close();
        if ($this->onClose !== null) {
            call_user_func($this->onClose, $this);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @return bool
     */
    public function isHandshake(): bool
    {
        return $this->step === 1;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @return int
     */
    public function getId(): int
    {
        return $this->stream->id;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @return string
     */
    public function getHeaderContent(): string
    {
        return $this->headerContent;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @param Closure $onMessage
     * @return void
     */
    public function onMessage(Closure $onMessage): void
    {
        $this->onMessage = $onMessage;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @param Closure $onConnect
     * @return void
     */
    public function onConnect(Closure $onConnect): void
    {
        $this->onConnect = $onConnect;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @param Closure $onClose
     * @return void
     */
    public function onClose(Closure $onClose): void
    {
        $this->onClose = $onClose;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @param string $context
     * @param int    $opcode
     * @param bool   $fin
     * @return string
     */
    private function build(string $context, int $opcode = 0x1, bool $fin = true): string
    {
        $frame      = chr(($fin ? 0x80 : 0) | $opcode);
        $contextLen = strlen($context);
        if ($contextLen < 126) {
            $frame .= chr($contextLen);
        } elseif ($contextLen <= 0xFFFF) {
            $frame .= chr(126) . pack('n', $contextLen);
        } else {
            $frame .= chr(127) . pack('J', $contextLen);
        }
        $frame .= $context;
        return $frame;
    }

    /**
     * @Author lidongyooo
     * @Date   2024/8/25 22:43
     * @return void
     */
    protected function frameType(): void
    {
        $firstByte = ord($this->buffer[0]);
        $opcode = $firstByte & 0x0F;

        switch ($opcode) {
            case Type::PING:
                $this->pong();
                break;
            case Type::BINARY:
            case Type::CLOSE:
            case Type::TEXT:
            case Type::PONG:
            default:
                break;
        }
    }

    /**
     * @Author lidongyooo
     * @Date   2024/8/25 22:43
     * @return bool
     */
    protected function pong(): bool
    {
        if (!$this->server->getOptions()->getPingPong()) {
            return false;
        }

        return $this->sendFrame('', opcode: TYPE::PONG);
    }

    /**
     * @Author lidongyooo
     * @Date  2024/8/25 22:43
     * @param string $context
     * @param int    $opcode
     * @param bool   $fin
     * @return bool
     */
    public function sendFrame(string $context, int $opcode = 0x1, bool $fin = true): bool
    {
        try {
            if (!$this->isHandshake()) {
                throw new ConnectionException('Connection is not established yet');
            }
            $this->stream->write($this->build($context, $opcode, $fin));
        } catch (ConnectionException) {
            $this->close();
            return false;
        }
        return true;
    }

    /**
     * @Date   2024/8/15 14:45
     * @return array
     */
    private function parse(): array
    {
        if (strlen($this->buffer) > 0) {
            $this->frameType();
        }

        $results = array();
        while (strlen($this->buffer) > 0) {
            $context = $this->buffer;
            $dataLength = strlen($context);
            $index = 0;

            // 验证足够的数据来读取第一个字节
            if ($dataLength < 2) {
                break; // 不够用
            }

            $byte = ord($context[$index++]);
            $fin = ($byte & 0x80) != 0;
            $opcode = $byte & 0x0F;

            $byte = ord($context[$index++]);
            $mask = ($byte & 0x80) != 0;
            $payloadLength = $byte & 0x7F;

            // 处理 2 字节或 8 字节的长度字段
            if ($payloadLength > 125) {
                if ($payloadLength == 126) {
                    // 验证足够的数据来读取 2 字节的长度字段
                    if ($dataLength < $index + 2) {
                        break; // 不够用
                    }
                    $payloadLength = unpack('n', substr($context, $index, 2))[1];
                    $index += 2;
                } else {
                    // 验证足够的数据来读取 8 字节的长度字段
                    if ($dataLength < $index + 8) {
                        break; // 不够用
                    }
                    $payloadLength = unpack('J', substr($context, $index, 8))[1];
                    $index += 8;
                }
            }

            // 处理掩码密钥
            if ($mask) {
                // 验证足够的数据来读取掩码密钥
                if ($dataLength < $index + 4) {
                    break; // 不够用
                }
                $maskingKey = substr($context, $index, 4);
                $index += 4;
            }

            // 验证足够的数据来读取负载数据
            if ($dataLength < $index + $payloadLength) {
                break; // 不够用
            }

            // 处理负载数据
            $payload = substr($context, $index, $payloadLength);
            if ($mask) {
                for ($i = 0; $i < strlen($payload); $i++) {
                    $payload[$i] = chr(ord($payload[$i]) ^ ord($maskingKey[$i % 4]));
                }
            }

            $this->buffer = substr($context, $index + $payloadLength);
            $results[] = $payload;
        }

        return $results;
    }
}
