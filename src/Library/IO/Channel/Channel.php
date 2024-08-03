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

namespace Psc\Library\IO\Channel;

use Exception;
use P\IO;
use Psc\Core\Stream\Stream;
use Psc\Drive\Stream\Zx7e;
use Psc\Library\IO\Channel\Exception\ChannelException;
use Psc\Library\IO\Lock\Lock;

use function chr;
use function file_exists;
use function fopen;
use function md5;
use function P\cancelForkHandler;
use function P\registerForkHandler;
use function posix_mkfifo;
use function serialize;
use function sys_get_temp_dir;
use function unlink;
use function unpack;
use function unserialize;

/**
 *
 */
class Channel
{
    private const FRAME_HEADER = 0x7E;

    private const FRAME_FOOTER = 0x7E;

    /*** @var Zx7e */
    private Zx7e $zx7e;

    /*** @var bool */
    private bool $blocking = true;

    /*** @var int */
    private int $forkHandlerId;

    /*** @var Stream */
    private Stream $stream;

    /*** @var Lock */
    private Lock   $readLock;

    /*** @var Lock */
    private Lock $writeLock;

    /*** @var string */
    private string $path;

    /**
     * @param string $name
     * @param bool   $owner
     * @throws ChannelException
     */
    public function __construct(
        private readonly string $name,
        private bool            $owner = false
    ) {
        $this->path = self::generateFilePathByChannelName($name);
        $this->readLock = IO::Lock()->access("{$this->name}.read");
        $this->writeLock = IO::Lock()->access("{$this->name}.write");

        if (!file_exists($this->path)) {
            if (!$this->owner) {
                throw new ChannelException('Channel does not exist.');
            }

            if (!posix_mkfifo($this->path, 0600)) {
                throw new ChannelException('Failed to create channel.');
            }
        }

        $this->stream = new Stream(fopen($this->path, 'r+'));
        $this->zx7e   = new Zx7e();

        // 注册进程fork后重新打开流资源
        $this->forkHandlerId = registerForkHandler(function () {
            $this->owner = false;
            $this->stream->close();

            $this->stream = new Stream(fopen($this->path, 'r+'));
            $this->zx7e   = new Zx7e();
        });
    }

    /**
     * @param mixed $data
     * @return bool
     * @throws ChannelException
     */
    public function send(mixed $data): bool
    {
        if(!file_exists($this->path)) {
            throw new ChannelException('Unable to send data to a closed channel');
        }

        $this->writeLock->lock();
        $this->stream->setBlocking(true);

        try {
            $this->stream->write($this->zx7e->encodeFrame(serialize($data)));
        } catch (Exception) {
            return false;
        } finally {
            $this->writeLock->unlock();
        }
        return true;
    }

    /**
     * @return mixed
     * @throws ChannelException
     */
    public function receive(): mixed
    {
        if(!file_exists($this->path)) {
            throw new ChannelException('Unable to receive data from a closed channel');
        }

        try {
            if ($this->blocking) {
                $this->readLock->lock();
                $this->stream->setBlocking(true);
            } else {
                if (!$this->readLock->lock(false)) {
                    throw new Exception('Failed to acquire lock.');
                }
                $this->stream->setBlocking(false);
            }

            $header = $this->stream->read(1);

            if ($header !== chr(self::FRAME_HEADER)) {
                $this->readLock->unlock();
                return null;
            }

            $this->stream->setBlocking(true);

            $length   = $this->stream->read(2);
            $data     = $this->stream->read(unpack('n', $length)[1]);
            $checksum = $this->stream->read(1);
            $footer   = $this->stream->read(1);

            if ($footer !== chr(self::FRAME_FOOTER)) {
                $this->readLock->unlock();
                throw new Exception('Failed to read frame footer.');
            }

            if ($checksum !== chr($this->zx7e->calculateChecksum($data))) {
                $this->readLock->unlock();
                throw new Exception('Failed to verify checksum.');
            }

            $this->readLock->unlock();
            return unserialize($data);
        } catch (Exception $e) {
            throw new ChannelException($e->getMessage());
        } finally {
            $this->readLock->unlock();
        }
    }

    /*** @return string */
    public function getName(): string
    {
        return $this->name;
    }

    /*** @return string */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @param bool $blocking
     * @return void
     */
    public function setBlocking(bool $blocking): void
    {
        $this->blocking = $blocking;
    }

    /*** @var bool */
    private bool $closed = false;

    /*** @return void */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->stream->close();
        $this->readLock->close();
        $this->writeLock->close();

        if ($this->owner) {
            unlink($this->path);
        }

        $this->closed = true;

        cancelForkHandler($this->forkHandlerId);
    }

    /****/
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param string $name
     * @return string
     */
    private static function generateFilePathByChannelName(string $name): string
    {
        $name = md5($name);
        return sys_get_temp_dir() . '/' . $name . '.channel';
    }

    /**
     * @param string $name
     * @return Channel
     * @throws ChannelException
     */
    public static function make(string $name): Channel
    {
        return new self($name, true);
    }

    /**
     * @param string $name
     * @return Channel
     * @throws ChannelException
     */
    public static function open(string $name): Channel
    {
        $path = self::generateFilePathByChannelName($name);

        if (!file_exists($path)) {
            throw new ChannelException('Channel does not exist.');
        }

        return new self($name);
    }
}
