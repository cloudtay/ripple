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
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Core\Stream\Stream;
use Psc\Core\WebSocket\Frame\Type;
use Psc\Utils\Output;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function array_merge;
use function array_shift;
use function base64_encode;
use function call_user_func;
use function chr;
use function count;
use function deflate_add;
use function deflate_init;
use function explode;
use function inflate_add;
use function inflate_init;
use function ord;
use function pack;
use function parse_url;
use function rawurldecode;
use function sha1;
use function str_replace;
use function stripos;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use function trim;
use function unpack;

use const PHP_URL_PATH;
use const ZLIB_DEFAULT_STRATEGY;
use const ZLIB_ENCODING_RAW;

/**
 * @Author cclilshy
 * @Date   2024/8/15 14:44
 */
class Connection
{
    /**
     *
     */
    private const NEED_HEAD = array(
        'Host'                  => true,
        'Upgrade'               => true,
        'Connection'            => true,
        'Sec-WebSocket-Key'     => true,
        'Sec-WebSocket-Version' => true
    );

    private const EXTEND_HEAD = 'Sec-WebSocket-Extensions';

    private bool $isDeflate = false;

    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * @Description 已使用Request装载
     * @var string
     */
    private string $headerContent = '';

    /**
     * @var Request
     */
    private Request $request;

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
     * @param SocketStream $stream
     * @param Server       $server
     */
    public function __construct(public readonly SocketStream $stream, private readonly Server $server)
    {
        $this->stream->onReadable(fn (SocketStream $stream) => $this->handleRead($stream));
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
            $data = '';
            while ($buffer = $stream->read(8192)) {
                $data .= $buffer;
            }
            if ($data === '') {
                if ($stream->eof()) {
                    throw new ConnectionException('Connection closed by peer');
                }
                return;
            }
            $this->push($data);
        } catch (ConnectionException) {
            $this->close();
            return;
        } catch (Throwable $exception) {
            Output::warning($exception->getMessage());
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
    private function push(string $data): void
    {
        $this->buffer .= $data;
        if ($this->step === 0) {
            $handshake = $this->accept();
            if ($handshake === null) {
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
     * @param string $context
     * @param int    $opcode
     * @param bool   $fin
     * @return string
     */
    private function build(string $context, int $opcode = 0x1, bool $fin = true): string
    {
        $frame      = chr(($fin ? 0x80 : 0) | $opcode);
        if ($this->isDeflate && $opcode === 0x1) {
            $frame[0] = chr(ord($frame[0]) | 0x40);
            $context = $this->deflate($context);
        }

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
    private function frameType(): void
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
    private function pong(): bool
    {
        if (!$this->server->getOptions()->getPingPong()) {
            return false;
        }

        return $this->sendFrame('', opcode: TYPE::PONG);
    }


    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:45
     * @return array
     */
    private function parse(): array
    {
        if (strlen($this->buffer) > 0) {
            $this->frameType();
        }

        $results = array();
        $prevPayload = '';
        while (strlen($this->buffer) > 0) {
            $context = $this->buffer;
            $dataLength = strlen($context);
            $index = 0;

            // 验证足够的数据来读取第一个字节
            if ($dataLength < 2) {
                // 等待更多数据...
                break;
            }

            $byte = ord($context[$index++]);
            $fin = ($byte & 0x80) != 0;
            $opcode = $byte & 0x0F;
            $rsv1 = 64 === ($byte & 64);

            $byte = ord($context[$index++]);
            $mask = ($byte & 0x80) != 0;
            $payloadLength = $byte & 0x7F;

            // 处理 2 or 8 字节的长度字段
            if ($payloadLength > 125) {
                if ($payloadLength == 126) {
                    // 验证足够的数据来读取 2 字节的长度字段
                    if ($dataLength < $index + 2) {
                        // 等待更多数据...
                        break;
                    }
                    $payloadLength = unpack('n', substr($context, $index, 2))[1];
                    $index += 2;
                } else {
                    // 验证足够的数据来读取 8 字节的长度字段
                    if ($dataLength < $index + 8) {
                        // 等待更多数据...
                        break;
                    }
                    $payloadLength = unpack('J', substr($context, $index, 8))[1];
                    $index += 8;
                }
            }

            // 处理掩码密钥
            if ($mask) {
                // 验证足够的数据来读取掩码密钥
                if ($dataLength < $index + 4) {
                    // 等待更多数据...
                    break;
                }
                $maskingKey = substr($context, $index, 4);
                $index += 4;
            }

            // 验证足够的数据来读取负载数据
            if ($dataLength < $index + $payloadLength) {
                // 等待更多数据...
                break;
            }

            // 处理负载数据
            $payload = substr($context, $index, $payloadLength);
            if ($mask) {
                for ($i = 0; $i < strlen($payload); $i++) {
                    $payload[$i] = chr(ord($payload[$i]) ^ ord($maskingKey[$i % 4]));
                }
            }

            if ($rsv1) {
                $payload = $this->inflate($payload, $fin);
            }

            $this->buffer = substr($context, $index + $payloadLength);

            $prevPayload .= $payload;
            if ($fin) {
                $results[] = $prevPayload;
                $prevPayload = '';
            }
        }

        return $results;
    }

    // 解压
    protected function inflate($payload, $fin): bool|string
    {
        if (!isset($this->inflator)) {
            $this->inflator = inflate_init(
                ZLIB_ENCODING_RAW,
                [
                    'level'    => -1,
                    'memory'   => 8,
                    'window'   => 9,
                    'strategy' => ZLIB_DEFAULT_STRATEGY
                ]
            );
        }

        if ($fin) {
            $payload .= "\x00\x00\xff\xff";
        }

        return inflate_add($this->inflator, $payload);
    }

    //压缩
    protected function deflate($payload): string
    {
        if (!isset($this->deflator)) {
            $this->deflator = deflate_init(
                ZLIB_ENCODING_RAW,
                [
                    'level'    => -1,
                    'memory'   => 8,
                    'window'   => 9,
                    'strategy' => ZLIB_DEFAULT_STRATEGY
                ]
            );
        }

        return substr(deflate_add($this->deflator, $payload), 0, -4);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     * @return bool|null 返回null表示未完成握手, 返回false表示握手失败, 返回true表示握手成功
     * @throws ConnectionException
     */
    private function accept(): bool|null
    {
        $identityInfo = $this->tick();
        if ($identityInfo === null) {
            return null;
        } elseif ($identityInfo === false) {
            return false;
        } else {
            $secWebSocketAccept = $this->getSecWebSocketAccept($identityInfo->headers->get('Sec-WebSocket-Key'));
            $this->stream->write($this->generateResponseContent($secWebSocketAccept));
            return true;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     * @return Request|false|null
     */
    private function tick(): Request|false|null
    {
        if ($index = strpos($this->buffer, "\r\n\r\n")) {
            $verify = Connection::NEED_HEAD;
            $lines  = explode("\r\n", $this->buffer);
            $header = array();

            if (count($firstLineInfo = explode(" ", array_shift($lines))) !== 3) {
                return false;
            } else {
                $header['method']  = $firstLineInfo[0];
                $header['url']     = $firstLineInfo[1];
                $header['version'] = $firstLineInfo[2];
            }

            foreach ($lines as $line) {
                if ($_ = explode(":", $line)) {
                    $header[trim($_[0])] = trim($_[1] ?? '');
                    unset($verify[trim($_[0])]);
                }
            }

            if (count($verify) > 0) {
                return false;
            } else {
                $this->buffer = substr($this->buffer, $index + 4);
                // 到此处表示Request完毕可以触发onRequest事件

                # query
                $query       = [];
                $queryStr    = $header['url'];
                $urlExploded = explode('?', $queryStr);
                $path        = parse_url($queryStr, PHP_URL_PATH);

                if (isset($urlExploded[1])) {
                    $queryArray = explode('&', $urlExploded[1]);
                    foreach ($queryArray as $item) {
                        $item = explode('=', $item);
                        if (count($item) === 2) {
                            $query[$item[0]] = $item[1];
                        }
                    }
                }

                # server
                $server = [
                    'REQUEST_METHOD'  => $header['method'],
                    'REQUEST_URI'     => $path,
                    'SERVER_PROTOCOL' => $header['version'],

                    'REMOTE_ADDR' => $this->stream->getHost(),
                    'REMOTE_PORT' => $this->stream->getPort(),
                    'HTTP_HOST'   => $header['Host'],
                ];

                # cookie
                $cookies = [];
                foreach ($header as $key => $value) {
                    $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
                }

                if (isset($server['HTTP_COOKIE'])) {
                    $cookie = $server['HTTP_COOKIE'];
                    $cookie = explode('; ', $cookie);
                    foreach ($cookie as $item) {
                        $item              = explode('=', $item);
                        $cookies[$item[0]] = rawurldecode($item[1]);
                    }
                }

                $this->request = new Request($query, [], [], $cookies, [], $server);

                if ($this->onRequest) {
                    call_user_func($this->onRequest, $this->request, $this);
                }
                return $this->request;
            }
        } else {
            return null;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     * @param string $key
     * @return string
     */
    private function getSecWebSocketAccept(string $key): string
    {
        return base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:48
     * @param string $accept
     * @return string
     */
    private function generateResponseContent(string $accept): string
    {
        $headers = array_merge(array(
            'Upgrade'              => 'websocket',
            'Connection'           => 'Upgrade',
            'Sec-WebSocket-Accept' => $accept,
        ), $this->extensions());
        $context = "HTTP/1.1 101 Switching Protocols\r\n";
        foreach ($headers as $key => $value) {
            $context .= "{$key}: {$value} \r\n";
        }
        $context .= "\r\n";

        return $context;
    }

    private function extensions(): array
    {
        $extendHeaders = [];
        $clientExtendHead = $this->getRequest()->headers->get(Connection::EXTEND_HEAD);
        if (!$clientExtendHead) {
            return $extendHeaders;
        }

        $value = '';
        $isDeflate = stripos($clientExtendHead, 'permessage-deflate') !== false;
        if ($isDeflate && $this->server->getOptions()->getDeflate()) {
            $value .= 'permessage-deflate; server_no_context_takeover; client_max_window_bits=15';
            $this->isDeflate = true;
        }
        //其他扩展，如：加密……

        if ($value) {
            $extendHeaders[Connection::EXTEND_HEAD] = $value;
        }

        return $extendHeaders;
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
     * @Description 已使用Request对象装载请求信息
     * @Author      cclilshy
     * @Date        2024/8/15 14:45
     * @return string
     */
    public function getHeaderContent(): string
    {
        return $this->headerContent;
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
     * @Author lidongyooo
     * @Date   2024/8/25 22:43
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
     * @Date   2024/8/30 14:51
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
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

    /*** @var Closure|null */
    private Closure|null $onRequest = null;

    /**
     * @Author cclilshy
     * @Date   2024/8/30 15:13
     * @param Closure $onRequest
     * @return void
     */
    public function onRequest(Closure $onRequest): void
    {
        $this->onRequest = $onRequest;
    }
}
