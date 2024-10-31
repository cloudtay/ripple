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

namespace Ripple\Socket;

use Closure;
use Co\IO;
use Ripple\Promise;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use RuntimeException;
use Socket;
use Throwable;

use function explode;
use function file_exists;
use function intval;
use function socket_get_option;
use function socket_import_stream;
use function socket_last_error;
use function socket_recv;
use function socket_set_option;
use function socket_strerror;
use function stream_socket_accept;
use function stream_socket_get_name;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const SO_SNDLOWAT;
use const SOL_SOCKET;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:36
 */
class SocketStream extends Stream
{
    /*** @var Socket */
    public Socket $socket;

    /*** @var bool */
    protected bool $blocking = false;

    /*** @var Stream|null */
    protected Stream|null $storageCacheWrite = null;

    /*** @var Stream|null */
    protected Stream|null $storageCacheRead = null;

    /*** @var string|null */
    protected string|null $address;

    /*** @var string|null */
    protected string|null $host;

    /*** @var int|null */
    protected int|null $port;

    /*** @var Promise */
    protected Promise $writePromise;

    /**
     * @param mixed       $resource
     * @param string|null $peerName
     */
    public function __construct(mixed $resource, string|null $peerName = null)
    {
        parent::__construct($resource);

        if (!$socket = socket_import_stream($this->stream)) {
            throw new RuntimeException('Failed to import stream');
        }

        $this->socket = $socket;

        if (!$peerName) {
            $peerName = stream_socket_get_name($this->stream, true);
        }

        if ($peerName) {
            $this->address = $peerName;
        } else {
            $this->address = null;
        }

        if ($this->address) {
            $exploded   = explode(':', $this->address);
            $this->host = $exploded[0];
            $this->port = intval($exploded[1] ?? 0);
        }
    }

    /**
     * @param int|float $timeout
     *
     * @return \Ripple\Socket\SocketStream|false
     */
    public function accept(int|float $timeout = 0): SocketStream|false
    {
        $socket = @stream_socket_accept($this->stream, $timeout, $peerName);
        if ($socket === false) {
            return false;
        }
        return new static($socket, $peerName);
    }

    /**
     * @param int   $level
     * @param int   $option
     * @param mixed $value
     *
     * @return void
     */
    public function setOption(int $level, int $option, mixed $value): void
    {
        if (!socket_set_option($this->socket, $level, $option, $value)) {
            throw new RuntimeException('Failed to set socket option: ' . socket_strerror(socket_last_error($this->socket)));
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/2 20:41
     *
     * @param int      $length
     * @param mixed    $target
     * @param int|null $flags
     *
     * @return int
     * @throws ConnectionException
     */
    public function receive(int $length, mixed &$target, int|null $flags = 0): int
    {
        $realLength = socket_recv($this->socket, $target, $length, $flags);
        if ($realLength === false) {
            throw new ConnectionException(
                'Unable to read from stream',
                ConnectionException::CONNECTION_READ_FAIL,
                null,
                $this
            );
        }
        return $realLength;
    }

    /**
     * @param string $string
     *
     * @return int
     * @throws ConnectionException
     */
    public function write(string $string): int
    {
        try {
            return $this->writeInternal($string);
        } catch (Throwable $e) {
            throw new ConnectionException(
                $e->getMessage(),
                ConnectionException::CONNECTION_WRITE_FAIL,
                null,
                $this
            );
        }
    }

    /**
     * @param string $string
     * @param bool   $wait
     *
     * @return int
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    public function writeInternal(string $string, bool $wait = true): int
    {
        $writeLength = 0;
        if (!$this->blocking) {
            $length       = parent::write($string);
            $string2cache = substr($string, $length);
            if ($string2cache === '') {
                return $length;
            }

            $writeLength += $length;

            $this->blocking = true;
            $tempFilePath   = sys_get_temp_dir() . '/' . uniqid('buf_');

            $this->storageCacheWrite = IO::File()->open($tempFilePath, 'w+');
            $this->storageCacheWrite->setBlocking(true);

            $this->storageCacheRead = IO::File()->open($tempFilePath, 'r+');
            $this->storageCacheRead->setBlocking(false);

            $eventID = $this->onClose(function () use ($tempFilePath) {
                $this->cleanupTempFiles($tempFilePath);
            });

            $this->onWriteable(function (SocketStream $_, Closure $cancel) use ($tempFilePath, $eventID) {
                if ($buffer = $this->storageCacheRead->read($this->getOption(SOL_SOCKET, SO_SNDLOWAT))) {
                    try {
                        parent::write($buffer);
                    } catch (ConnectionException $e) {
                        $this->blocking = false;
                        $this->cleanupTempFiles($tempFilePath);
                        $cancel();
                        $this->cancelOnClose($eventID);

                        if (isset($this->writePromise)) {
                            $this->writePromise->reject($e);

                            unset($this->writePromise);
                        }
                        return;
                    }
                }

                if ($this->storageCacheRead->eof()) {
                    $this->blocking = false;
                    $this->cleanupTempFiles($tempFilePath);
                    $cancel();
                    $this->cancelOnClose($eventID);

                    if (isset($this->writePromise)) {
                        $this->writePromise->resolve(0);

                        unset($this->writePromise);
                    }
                }
            });
        } else {
            $string2cache = $string;
        }

        $writeLength += $this->storageCacheWrite->write($string2cache);

        /*** @var Promise $writePromise */
        if ($wait) {
            if (!isset($this->writePromise)) {
                $this->writePromise = \Co\promise(static function () {
                });
            }

            try {
                $this->writePromise->await();
                return strlen($string);
            } catch (Throwable $e) {
                throw new ConnectionException(
                    $e->getMessage(),
                    ConnectionException::CONNECTION_WRITE_FAIL,
                    null,
                    $this
                );
            }
        }

        return $writeLength;
    }

    /**
     * Clean up temp files and close file handles.
     *
     * @param string $tempFilePath
     */
    protected function cleanupTempFiles(string $tempFilePath): void
    {
        $this->storageCacheWrite->close();
        $this->storageCacheRead->close();
        if (file_exists($tempFilePath)) {
            unlink($tempFilePath);
        }
        $this->storageCacheWrite = null;
        $this->storageCacheRead  = null;
    }

    /**
     * @param int $level
     * @param int $option
     *
     * @return array|int
     */
    public function getOption(int $level, int $option): array|int
    {
        $option = socket_get_option($this->socket, $level, $option);
        if ($option === false) {
            throw new RuntimeException('Failed to get socket option: ' . socket_strerror(socket_last_error($this->socket)));
        }
        return $option;
    }

    /**
     * @return string
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @param int $length
     *
     * @return string
     * @throws ConnectionException
     */
    public function readContinuously(int $length): string
    {
        $content = '';
        while ($buffer = $this->read($length)) {
            $content .= $buffer;
        }

        return $content;
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/29 11:01
     * @return SocketStream
     * @throws ConnectionException
     */
    public function enableSSL(): SocketStream
    {
        return IO::Socket()->enableSSL($this);
    }
}
