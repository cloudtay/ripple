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

namespace Ripple;

use Exception;
use Ripple\Channel\Exception\ChannelException;
use Ripple\File\Lock\Lock;
use Ripple\Utils\Serialization\Zx7e;
use Ripple\Utils\Utils;
use Throwable;

use function chr;
use function Co\cancelForked;
use function Co\forked;
use function file_exists;
use function fopen;
use function posix_mkfifo;
use function serialize;
use function touch;
use function unlink;
use function unpack;
use function unserialize;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:35
 */
class Channel
{
    protected const FRAME_HEADER = 0x7E;

    protected const FRAME_FOOTER = 0x7E;

    /*** @var Zx7e */
    protected Zx7e $zx7e;

    /*** @var string */
    protected string $forkHandlerID;

    /*** @var Stream */
    protected Stream $stream;

    /*** @var Lock */
    protected Lock $readLock;

    /*** @var Lock */
    protected Lock $writeLock;

    /*** @var string */
    protected string $path;

    /*** @var bool */
    protected bool $closed = false;

    /**
     * @param string $name
     * @param bool   $owner
     */
    public function __construct(
        protected readonly string $name,
        protected bool            $owner = false
    ) {
        $this->path      = Utils::tempPath($this->name, 'channel');
        $this->readLock  = \Co\lock("{$this->name}.read");
        $this->writeLock = \Co\lock("{$this->name}.write");

        if (!file_exists($this->path)) {
            if (!$this->owner) {
                throw new ChannelException('Channel does not exist.');
            }

            /*** @compatible:Windows */
            if (!Kernel::getInstance()->supportProcessControl()) {
                touch($this->path);
            } elseif (!posix_mkfifo($this->path, 0600)) {
                throw new ChannelException('Failed to create channel.');
            }
        }

        $this->openStream();

        // Re-open the stream resource after registering the process fork
        $this->forkHandlerID = forked(function () {
            $this->owner = false;
            $this->stream->close();

            $this->openStream();
        });
    }

    /**
     * @param string $name
     *
     * @return Channel
     */
    public static function make(string $name): Channel
    {
        return new Channel($name, true);
    }

    /**
     * @param mixed $data
     *
     * @return bool
     */
    public function send(mixed $data): bool
    {
        if (!file_exists($this->path)) {
            throw new ChannelException('Unable to send data to a closed channel');
        }

        $this->writeLock->lock();

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
     * @return void
     */
    protected function openStream(): void
    {
        $this->stream = new Stream(fopen($this->path, 'r+'));
        $this->stream->setBlocking(false);
        $this->zx7e = new Zx7e();
    }

    /**
     * @param bool $blocking
     *
     * @return mixed
     */
    public function receive(bool $blocking = true): mixed
    {
        if (!file_exists($this->path)) {
            throw new ChannelException('Unable to receive data from a closed channel');
        }

        while (1) {
            try {
                $blocking && $this->stream->waitForReadable();
            } catch (Throwable $e) {
                throw new ChannelException($e->getMessage());
            }

            if ($this->readLock->lock(blocking: false)) {
                try {
                    if ($this->stream->read(1) === chr(Channel::FRAME_HEADER)) {
                        break;
                    } else {
                        throw new Stream\Exception\ConnectionException('Failed to read frame header.');
                    }
                } catch (Stream\Exception\ConnectionException) {
                    $this->readLock->unlock();
                }
            }
        }

        try {
            $length   = $this->stream->read(2);
            $data     = $this->stream->read(unpack('n', $length)[1]);
            $checksum = $this->stream->read(1);
            $footer   = $this->stream->read(1);

            if ($footer !== chr(Channel::FRAME_FOOTER)) {
                throw new Exception('Failed to read frame footer.');
            }

            if ($checksum !== chr($this->zx7e->calculateChecksum($data))) {
                throw new Exception('Failed to verify checksum.');
            }

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
            file_exists($this->path) && unlink($this->path);
        }

        $this->closed = true;
        cancelForked($this->forkHandlerID);
    }

    public function __destruct()
    {
        $this->close();
    }
}
