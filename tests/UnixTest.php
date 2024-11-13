<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Throwable;

use function Co\cancelAll;
use function Co\defer;
use function Co\wait;
use function md5;
use function sys_get_temp_dir;
use function uniqid;

/**
 * @Author cclilshy
 * @Date   2024/8/15 14:49
 */
class UnixTest extends TestCase
{
    /**
     * @Author cclilshy
     * @Date   2024/8/16 10:16
     * @return void
     * @throws Throwable
     */
    #[Test]
    public function test_unix(): void
    {
        $path   = sys_get_temp_dir() . '/' . md5(uniqid()) . '.sock';
        $server = Socket::server('unix://' . $path);
        $server->setBlocking(false);

        $server->onReadable(function (Socket $stream) {
            $client = $stream->accept();
            $client->setBlocking(false);
            $client->onReadable(function (Socket $stream) {
                $data = $stream->read(1024);
                $stream->write($data);
            });
        });

        defer(function () use ($path) {
            try {
                $this->call($path);
            } catch (ConnectionException $exception) {
                Output::error($exception->getMessage());
            }
        });
        wait();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     *
     * @param string $path
     *
     * @return void
     * @throws ConnectionException
     */
    private function call(string $path): void
    {
        $client = Socket::connect('unix://' . $path);
        $client->setBlocking(false);

        $client->write('hello');
        $client->onReadable(function (Socket $stream) {
            $data = $stream->read(1024);
            $this->assertEquals('hello', $data);
            cancelAll();
        });
    }
}
