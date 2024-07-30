<?php declare(strict_types=1);

namespace Psc\Library\IO\Lock;

use function fclose;
use function file_exists;
use function flock;
use function fopen;
use function md5;
use function P\cancelForkHandler;
use function P\registerForkHandler;
use function sys_get_temp_dir;
use function touch;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;

class Lock
{
    /*** @var mixed|false|resource */
    private mixed $resource;

    /*** @var string */
    private string $path;

    private int $forkHandlerEventId;

    /**
     * @param string $name
     */
    public function __construct(private readonly string $name = 'default')
    {
        $this->path = self::generateFilePathByChannelName($this->name);

        if(!file_exists($this->path)) {
            touch($this->path);
        }

        $this->resource = fopen($this->path, 'r');

        $this->forkHandlerEventId = registerForkHandler(function () {
            fclose($this->resource);
            $this->resource = fopen($this->path, 'r');
        });
    }

    /**
     *
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param bool $blocking
     * @return bool
     */
    public function lock(bool $blocking = true): bool
    {
        return flock($this->resource, $blocking ? LOCK_EX : LOCK_EX | LOCK_NB);
    }

    /**
     * @param bool $blocking
     * @return bool
     */
    public function sharedLock(bool $blocking = true): bool
    {
        return flock($this->resource, $blocking ? LOCK_SH : LOCK_SH | LOCK_NB);
    }

    /**
     * @return bool
     */
    public function unlock(): bool
    {
        return flock($this->resource, LOCK_UN);
    }

    /**
     * @var bool
     */
    private bool $closed = false;

    /**
     * @return void
     */
    public function close(): void
    {
        if($this->closed) {
            return;
        }

        $this->closed = true;

        fclose($this->resource);
        cancelForkHandler($this->forkHandlerEventId);
    }

    /**
     * @param string $name
     * @return string
     */
    private static function generateFilePathByChannelName(string $name): string
    {
        $name = md5($name);
        return sys_get_temp_dir() . '/' . $name . '.lock';
    }
}
