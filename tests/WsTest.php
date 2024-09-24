<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

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
