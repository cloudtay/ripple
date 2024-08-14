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

use parallel\Channel;
use parallel\Events;
use parallel\Future;
use parallel\Runtime;
use parallel\Sync;
use Psc\Utils\Output;
use Revolt\EventLoop;

use function P\cancel;
use function P\defer;
use function P\onSignal;
use function P\tick;

include_once __DIR__ . '/../vendor/autoload.php';
Output::info(\strval(\posix_getpid()));
class Test
{
    public Runtime $counterRuntime;
    public Channel $counterChannel;
    public Future $counterFuture;
    public Sync $counterSync;
    public Events $events;
    private string $signalHandlerId;

    /**
     * @return void
     */
    public function startCounter(): void
    {
        if(isset($this->events)) {
            return;
        }

        $this->events = new Events();
        $this->events->setBlocking(true);
        $this->counterChannel = parallel\Channel::make('counter');
        $this->counterSync = new Sync(true);
        $this->counterRuntime = new Runtime();
        $this->counterFuture = $this->counterRuntime->run(static function (Channel $channel, Sync $sync) {
            $sync(fn () => $sync->wait());
            $processId = \posix_getpid();
            $count = 0;

            while($number = $channel->recv()) {
                $sync->set($count += $number);
                if($number > 0) {
                    \posix_kill($processId, \SIGUSR2);
                }

                if($count < 0) {
                    break;
                }
            }
        }, [
            $this->counterChannel,
            $this->counterSync
        ]);

        try {
            $this->signalHandlerId = onSignal(\SIGUSR2, fn () => $this->poll());
        } catch (EventLoop\UnsupportedFeatureException) {
            // ignore
        }

        defer(function () {
            $this->counterSync->notify();
        });
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function stopCounter(): void
    {
        $this->counterChannel->send(-1);
        $this->counterFuture->value();
        $this->counterRuntime->close();
        $this->counterChannel->close();
        cancel($this->signalHandlerId);

        unset($this->counterRuntime);
        unset($this->counterChannel);
        unset($this->counterFuture);
        unset($this->counterSync);
        unset($this->events);
        unset($this->signalHandlerId);
    }

    /**
     * @param Closure $closure
     * @param array   $params
     * @param string  $name
     * @return void
     */
    public function run(Closure $closure, array $params, string $name): void
    {
        $this->startCounter();
        $runtime = new Runtime();
        $future = $runtime->run(static function (Closure $closure, array $argv, Channel $channel) {
            try {
                return $closure(...$argv);
            } finally {
                $channel->send(1);
            }
        }, [
            $closure,
            $params,
            $this->counterChannel
        ]);
        $this->events->addFuture($name, $future);
    }

    /**
     * @return void
     * @throws Throwable
     */
    private function poll(): void
    {
        while($count = $this->counterSync->get()) {
            for ($i = 0; $i < $count; $i++) {
                $event = $this->events->poll();
                $this->counterChannel->send(-1);
            }
        }
        $this->stopCounter();
    }

    public function __destruct()
    {
        Output::warning('__destruct');
    }
}

$test = new Test();
$test->run(static function (string $name) {
    \var_dump('1');
    return $name;
}, ['name'], '1');
tick();

$test = new Test();

$test->run(static function (string $name) {
    \var_dump('2');
    return $name;
}, ['name'], '2');
tick();

\var_dump('ticked');
