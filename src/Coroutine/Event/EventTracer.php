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

namespace Ripple\Coroutine\Event;

use Ripple\Coroutine\Context;
use WeakMap;

use function Co\getContext;
use function debug_backtrace;
use function microtime;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

class EventTracer
{
    /*** @var EventTracer|null */
    private static EventTracer|null $instance = null;

    /*** @var EventDispatcher */
    private EventDispatcher $eventDispatcher;

    /*** @var WeakMap */
    private WeakMap $traces;

    /**
     *
     */
    private function __construct()
    {
        $this->traces = new WeakMap();
        $this->eventDispatcher = EventDispatcher::getInstance();
    }

    /**
     * @return EventTracer
     */
    public static function getInstance(): EventTracer
    {
        if (EventTracer::$instance === null) {
            EventTracer::$instance = new EventTracer();
        }
        return EventTracer::$instance;
    }

    /**
     * @param Event $event
     * @param Context|null $context
     * @return void
     */
    public function trace(Event $event, Context|null $context = null): void
    {
        $context = $context ?? getContext();
        if (!isset($this->traces[$context])) {
            $this->traces[$context] = [];
        }

        $this->traces[$context][] = new EventTrace(
            $event,
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
            microtime(true)
        );

        $this->eventDispatcher->dispatch($event);
    }

    /**
     * @param Context|null $context
     *
     * @return array
     */
    public function getTraces(Context|null $context = null): array
    {
        $context = $context ?? getContext();
        return $this->traces[$context] ?? [];
    }

    /**
     * @param Context|null $context
     *
     * @return void
     */
    public function clear(Context|null $context = null): void
    {
        $context = $context ?? getContext();
        unset($this->traces[$context]);
    }
}
