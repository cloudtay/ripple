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

namespace Psc\Library\System\Parallel;

use Closure;
use parallel\Events;
use Psc\Core\LibraryAbstract;
use Revolt\EventLoop\UnsupportedFeatureException;

use function P\cancel;
use function P\onSignal;
use function var_dump;

use const SIGUSR2;

/**
 * 允许你占用USR2信号，以便在主线程中执行并行代码
 */
class Parallel extends LibraryAbstract
{
    /*** @var LibraryAbstract */
    protected static LibraryAbstract $instance;

    /*** @var string */
    private string $signalEventId;

    /*** @var int */
    private int $index = 0;

    /*** @var Channel[] */
    private array $channels = [];

    /*** @var Future[] */
    private array $futures = [];

    /*** @var Events */
    private Events $events;

    /*** @var Channel */
    public Channel $futureChannel;

    public function __construct()
    {
        $this->events = new Events();
        $this->events->setBlocking(true);
        $this->futureChannel = $this->makeChannel('future', 1);
        $this->futureChannel->onRead(function (string $name) {
            if($future = $this->futures[$name] ?? null) {
                $future->resolve($future->value());
                unset($this->futures[$name]);
                $this->events->remove($name);
            }
        });
    }

    /**
     *
     */
    public function __destruct()
    {
        if (isset($this->signalEventId)) {
            cancel($this->signalEventId);
        }
    }

    /**
     * @param Closure $closure
     * @return Thread
     */
    public function thread(Closure $closure): Thread
    {
        try {
            $this->registerSignal();
        } catch (UnsupportedFeatureException $e) {

        }
        return new Thread($closure, $this, $this->index++);
    }

    /**
     * @param string $name
     * @return Channel
     */
    public function openChannel(string $name): Channel
    {
        $channel =  new Channel(\parallel\Channel::make($name));
        $this->listenChannel($name, $channel);
        $this->channels[$name] = $channel;
        return $channel;
    }

    /**
     * @param string   $name
     * @param int|null $capacity
     * @return Channel
     */
    public function makeChannel(string $name, int|null $capacity = null): Channel
    {
        $channel =  new Channel(\parallel\Channel::make($name, $capacity));
        $this->listenChannel($name, $channel);
        $this->channels[$name] = $channel;
        return $channel;
    }

    /**
     * @param Events\Event $event
     * @return void
     */
    private function onEvent(Events\Event $event): void
    {
        switch ($event->type) {
            case Events\Event\Type::Read:
            case Events\Event\Type::Write:
                if($channel = $this->channels[$event->source] ?? null) {
                    $channel->onEvent($event);
                }
                break;
            case Events\Event\Type::Close:
                if($channel = $this->channels[$event->source] ?? null) {
                    $channel->onEvent($event);
                }
                unset($this->channels[$event->source]);
                $this->events->remove($event->source);
                break;

            case Events\Event\Type::Kill:
            case Events\Event\Type::Error:
            case Events\Event\Type::Cancel:
                var_dump(1);
                if($future = $this->futures[$event->source] ?? null) {
                    $future->onEvent($event);
                }
                unset($this->futures[$event->source]);
                $this->events->remove($event->source);
                break;
        }

        if($this->events->count() === 0) {
            cancel($this->signalEventId);
            unset($this->signalEventId);
        }
    }

    /**
     * @param string  $name
     * @param Channel $channel
     * @return void
     */
    public function listenChannel(string $name, Channel $channel): void
    {
        try {
            $this->registerSignal();
        } catch (UnsupportedFeatureException $e) {
        }
        $this->events->addChannel($channel->channel);
        $this->channels[$name] = $channel;
    }

    /**
     * @param   string     $name
     * @param Future $future
     * @return void
     */
    public function listenFuture(string $name, Future $future): void
    {
        try {
            $this->registerSignal();
        } catch (UnsupportedFeatureException $e) {
        }
        $this->events->addFuture($name, $future->future);
        $this->futures[$name] = $future;
    }

    /**
     * @return void
     * @throws UnsupportedFeatureException
     */
    private function registerSignal(): void
    {
        if (!isset($this->signalEventId)) {
            $this->signalEventId = onSignal(SIGUSR2, function () {
                while($event = $this->events->poll()) {
                    $this->onEvent($event);
                }
            });
        }
    }
}
