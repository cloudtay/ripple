<?php declare(strict_types=1);

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
    private Lock   $lock;

    /*** @var string */
    private string $path;

    /**
     * @param string $name
     * @param bool   $owner
     * @throws Exception
     */
    public function __construct(
        private readonly string $name,
        private bool            $owner = false
    ) {
        $this->path = self::generateFilePathByChannelName($name);
        $this->lock = IO::Lock()->access($this->name);

        if (!file_exists($this->path)) {
            if (!$this->owner) {
                throw new Exception('Channel does not exist.');
            }

            if (!posix_mkfifo($this->path, 0600)) {
                throw new Exception('Failed to create channel.');
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

        $this->lock->lock();
        $this->stream->setBlocking(true);

        try {
            $this->stream->write($this->zx7e->encodeFrame(serialize($data)));
        } catch (Exception) {
            return false;
        } finally {
            $this->lock->unlock();
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
                $this->lock->lock();
                $this->stream->setBlocking(true);
            } else {
                if (!$this->lock->lock(false)) {
                    throw new Exception('Failed to acquire lock.');
                }
                $this->stream->setBlocking(false);
            }

            $header = $this->stream->read(1);

            if ($header !== chr(self::FRAME_HEADER)) {
                $this->lock->unlock();
                return null;
            }

            $this->stream->setBlocking(true);

            $length   = $this->stream->read(2);
            $data     = $this->stream->read(unpack('n', $length)[1]);
            $checksum = $this->stream->read(1);
            $footer   = $this->stream->read(1);

            if ($footer !== chr(self::FRAME_FOOTER)) {
                $this->lock->unlock();
                throw new Exception('Failed to read frame footer.');
            }

            if ($checksum !== chr($this->zx7e->calculateChecksum($data))) {
                $this->lock->unlock();
                throw new Exception('Failed to verify checksum.');
            }

            $this->lock->unlock();
            return unserialize($data);
        } catch (Exception $e) {
            throw new ChannelException($e->getMessage());
        } finally {
            $this->lock->unlock();
        }
    }

    /*** @return bool */
    public function getBlocking(): bool
    {
        return $this->blocking;
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

    /*** @return Lock */
    public function getLock(): Lock
    {
        return $this->lock;
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
        $this->lock->close();

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
     * @throws Exception
     */
    public static function make(string $name): Channel
    {
        return new self($name, true);
    }

    /**
     * @param string $name
     * @return Channel
     * @throws Exception
     */
    public static function open(string $name): Channel
    {
        $path = self::generateFilePathByChannelName($name);

        if (!file_exists($path)) {
            throw new Exception('Channel does not exist.');
        }

        return new self($name);
    }
}
