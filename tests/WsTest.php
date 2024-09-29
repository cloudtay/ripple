<?php declare(strict_types=1);

namespace Tests;

use Co\Net;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psc\Core\Coroutine\Promise;
use Psc\Core\WebSocket\Options;
use Psc\Core\WebSocket\Server\Connection;
use Psc\Utils\Output;
use Throwable;

use function Co\cancelAll;
use function Co\defer;
use function Co\wait;
use function gc_collect_cycles;
use function md5;
use function memory_get_usage;
use function stream_context_create;
use function uniqid;

/**
 * @Author cclilshy
 * @Date   2024/8/15 14:49
 */
class WsTest extends TestCase
{
    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     * @return void
     * @throws Throwable
     */
    #[Test]
    public function test_wsServer(): void
    {
        defer(function () {
            for ($i = 0; $i < 10; $i++) {
                try {
                    $this->wsTest()->await();
                } catch (Throwable $exception) {
                    Output::error($exception->getMessage());
                }
            }

            gc_collect_cycles();
            $baseMemory = memory_get_usage();

            for ($i = 0; $i < 10; $i++) {
                try {
                    $this->wsTest()->await();
                } catch (Throwable $exception) {
                    Output::error($exception->getMessage());
                }
            }

            gc_collect_cycles();
            if ($baseMemory !== memory_get_usage()) {
                Output::warning('There may be a memory leak');
            }
            cancelAll();
            $this->assertTrue(true);
        });

        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
            ],
        ]);

        $server = Net::WebSocket()->server(
            'ws://127.0.0.1:8001/',
            $context,
            new Options(true, true)
        );
        $server->onMessage(static function (string $data, Connection $connection) {
            $connection->send($data);
        });
        $server->listen();
        wait();
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     * @return Promise
     */
    private function wsTest(): Promise
    {
        return \Co\promise(function ($r) {
            $hash   = md5(uniqid());
            $client = Net::WebSocket()->connect('ws://127.0.0.1:8001/');
            $client->onOpen(static function () use ($client, $hash) {
                \Co\sleep(0.1);
                $client->send($hash);
            });

            $client->onMessage(function (string $data) use ($hash, $r) {
                $this->assertEquals($hash, $data);
                $r();
            });
        });
    }
}
