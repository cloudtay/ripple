<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Channel;

use Exception;
use Ripple\Channel\Exception\ChannelException;
use Ripple\File\Lock;
use Ripple\Kernel;
use Ripple\Stream;
use Ripple\Utils\Path;
use Ripple\Utils\Serialization\Zx7e;
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
    public function __construct(protected readonly string $name, protected bool $owner = false)
    {
        $this->path = Path::temp($this->name, 'channel');
        $this->readLock  = \Co\lock("w{$this->name}.read");
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
     * @return void
     */
    protected function openStream(): void
    {
        $this->stream = new Stream(fopen($this->path, 'r+'));
        $this->stream->setBlocking(false);
        $this->zx7e = new Zx7e();
    }

    /**
     * @return void
     */
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
     * @param string $name
     *
     * @return \Ripple\Channel\Channel
     */
    public static function open(string $name): Channel
    {
        return new Channel($name, false);
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
            } catch (Throwable $exception) {
                throw new ChannelException($exception->getMessage());
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
        } catch (Exception $exception) {
            throw new ChannelException($exception->getMessage());
        } finally {
            $this->readLock->unlock();
        }
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        $this->close();
    }
}
