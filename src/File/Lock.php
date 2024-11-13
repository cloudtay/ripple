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

namespace Ripple\File;

use Ripple\Utils\Path;

use function Co\cancelForked;
use function Co\forked;
use function fclose;
use function file_exists;
use function flock;
use function fopen;
use function is_resource;
use function touch;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_SH;
use const LOCK_UN;

class Lock
{
    /*** @var resource|false */
    private mixed $resource;

    /*** @var string */
    private string $path;

    /*** @var string */
    private string $forkHandlerEventID;

    /*** @var bool */
    private bool $closed = false;

    /**
     * @param string $name
     */
    public function __construct(private readonly string $name = 'default')
    {
        $this->path = Path::temp($this->name, 'lock');

        if (!file_exists($this->path)) {
            touch($this->path);
        }

        $this->resource = fopen($this->path, 'r');

        $this->forkHandlerEventID = forked(function () {
            fclose($this->resource);
            $this->resource = fopen($this->path, 'r');
        });
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (is_resource($this->resource)) {
            fclose($this->resource);
        }

        cancelForked($this->forkHandlerEventID);
    }

    /**
     * @param int $flag
     * @param bool $blocking
     *
     * @return bool
     */
    public function lock(int $flag = LOCK_EX, bool $blocking = true): bool
    {
        return flock($this->resource, $blocking ? $flag : $flag | LOCK_NB);
    }

    /**
     * @param bool $blocking
     *
     * @return bool
     */
    public function exclusion(bool $blocking = true): bool
    {
        return flock($this->resource, $blocking ? LOCK_EX : LOCK_EX | LOCK_NB);
    }

    /**
     * @param bool $blocking
     *
     * @return bool
     */
    public function shareable(bool $blocking = true): bool
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
}
