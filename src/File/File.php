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

use Ripple\Stream;
use Ripple\Support;

use function array_shift;
use function Co\forked;
use function fopen;
use function file_get_contents;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:35
 */
class File extends Support
{
    /*** @var Support */
    protected static Support $instance;

    /*** @var Monitor[] */
    private array $monitors = array();

    public function __construct()
    {
        $this->registerOnFork();
    }

    /**
     * @return void
     */
    private function registerOnFork(): void
    {
        forked(function () {
            while ($monitor = array_shift($this->monitors)) {
                $monitor->stop();
            }
            $this->registerOnFork();
        });
    }

    /**
     * @deprecated
     * @param string $path
     *
     * @return string|false
     */
    public static function getContents(string $path): string|false
    {
        return file_get_contents($path);
    }

    /**
     * @param string $path
     * @param string $mode
     *
     * @return Stream
     */
    public static function open(string $path, string $mode): Stream
    {
        return new Stream(fopen($path, $mode));
    }

    /**
     * @return Monitor
     */
    public function monitor(): Monitor
    {
        $monitor = new Monitor();
        $this->monitors[] = $monitor;
        return $monitor;
    }
}
