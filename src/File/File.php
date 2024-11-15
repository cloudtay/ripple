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

use Closure;
use Ripple\File\Exception\FileException;
use Ripple\Stream;
use Ripple\Support;
use Throwable;

use function array_shift;
use function Co\forked;
use function Co\promise;
use function fopen;

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
     * @param string $path
     *
     * @return string
     * @throws FileException
     */
    public static function getContents(string $path): string
    {
        try {
            return promise(static function (Closure $resolve, Closure $reject) use ($path) {
                if (!$resource = fopen($path, 'r')) {
                    $reject(new FileException('Failed to open file: ' . $path));
                    return;
                }

                $stream = new Stream($resource);
                $stream->setBlocking(false);
                $content = '';

                $stream->onReadable(static function (Stream $stream) use ($resolve, $reject, &$content) {
                    $fragment = '';
                    while ($buffer = $stream->read(8192)) {
                        $fragment .= $buffer;
                    }

                    if ($fragment === '') {
                        if ($stream->eof()) {
                            $stream->close();
                            $resolve($content);
                        }
                        return;
                    }

                    $content .= $fragment;

                    if ($stream->eof()) {
                        $stream->close();
                        $resolve($content);
                    }
                });
            })->await();
        } catch (Throwable $exception) {
            throw new FileException($exception->getMessage());
        }
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
     * @return \Ripple\File\Monitor
     */
    public function monitor(): Monitor
    {
        $monitor = new Monitor();
        $this->monitors[] = $monitor;
        return $monitor;
    }
}
