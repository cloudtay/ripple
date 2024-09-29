<?php declare(strict_types=1);

namespace Tests;

use Co\IO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Utils\Output;
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
        $server = IO::Socket()->server('unix://' . $path);
        $server->setBlocking(false);

        $server->onReadable(function (SocketStream $stream) {
            $client = $stream->accept();
            $client->setBlocking(false);
            $client->onReadable(function (SocketStream $stream) {
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
        $client = IO::Socket()->connect('unix://' . $path);
        $client->setBlocking(false);

        $client->write('hello');
        $client->onReadable(function (SocketStream $stream) {
            $data = $stream->read(1024);
            $this->assertEquals('hello', $data);
            cancelAll();
        });
    }
}
