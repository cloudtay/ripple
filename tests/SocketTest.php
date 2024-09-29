<?php declare(strict_types=1);

namespace Tests;

use Closure;
use Co\IO;
use PHPUnit\Framework\TestCase;
use Psc\Core\Coroutine\Exception\Exception;
use Psc\Core\Socket\SocketStream;
use Throwable;

use function Co\repeat;
use function Co\wait;
use function str_repeat;
use function stream_context_create;
use function strlen;

class SocketTest extends TestCase
{
    /**
     * @Author cclilshy
     * @Date   2024/9/24 17:08
     * @return void
     * @throws Exception
     * @throws Throwable
     */
    public function test_delayedBlocking(): void
    {
        $size   = 1024 * 1024 * 20;
        $current = 0;
        $listen = IO::Socket()->server('tcp://127.0.0.1:8002', stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1
            ],
        ]));

        $listen->onReadable(function (SocketStream $listen) use ($size, &$current) {
            $client = $listen->accept();
            $client->setBlocking(false);
            $listen->close();

            repeat(function (Closure $cancel) use ($client, $size, &$current) {
                $data = $client->read(102400);
                if ($data === '') {
                    $client->close();
                    $cancel();

                } else {
                    $current += strlen($data);
                }
            }, 0.1);
        });

        $server = IO::Socket()->connect('tcp://127.0.0.1:8002');
        $server->setBlocking(false);
        $data = str_repeat('A', $size);
        $server->write($data);

        wait();
        $this->assertEquals($size, $current, 'Socket read');
    }
}
