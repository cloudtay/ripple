<?php declare(strict_types=1);

namespace Psc\Library\Net\WebSocket\Server;

use Closure;
use Psc\Core\Stream\Stream;
use Psc\Std\Stream\Exception\ConnectionException;
use Throwable;

use function call_user_func;
use function chr;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;

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
     */
    public function __construct(public readonly Stream $stream)
    {
        $this->stream->onReadable(fn (Stream $stream) => $this->handleRead($stream));
        $this->stream->onClose(fn () => $this->close());
    }

    /**
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
     * @return bool
     */
    public function isHandshake(): bool
    {
        return $this->step === 1;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->stream->id;
    }

    /**
     * @return string
     */
    public function getHeaderContent(): string
    {
        return $this->headerContent;
    }

    /**
     * @param Closure $onMessage
     * @return void
     */
    public function onMessage(Closure $onMessage): void
    {
        $this->onMessage = $onMessage;
    }

    /**
     * @param Closure $onConnect
     * @return void
     */
    public function onConnect(Closure $onConnect): void
    {
        $this->onConnect = $onConnect;
    }

    /**
     * @param Closure $onClose
     * @return void
     */
    public function onClose(Closure $onClose): void
    {
        $this->onClose = $onClose;
    }

    /**
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
     * @return array
     */
    /**
     * @return array
     */
    private function parse(): array
    {
        $results = [];
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
