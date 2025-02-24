<?php declare(strict_types=1);

namespace Ripple\Event;

use Ripple\Coroutine\Context;
use WeakMap;
use Fiber;

use function debug_backtrace;
use function microtime;

use const DEBUG_BACKTRACE_IGNORE_ARGS;

class EventTracer
{
    /**
     * @var \Ripple\Event\EventTracer|null
     */
    private static EventTracer|null $instance = null;

    /**
     * @var WeakMap
     */
    private WeakMap $traces;

    /**
     *
     */
    private function __construct()
    {
        $this->traces = new WeakMap();
    }

    /**
     * @return \Ripple\Event\EventTracer
     */
    public static function getInstance(): EventTracer
    {
        if (EventTracer::$instance === null) {
            EventTracer::$instance = new EventTracer();
        }
        return EventTracer::$instance;
    }

    /**
     * @param \Ripple\Event\Event $event
     *
     * @return void
     */
    public function trace(Event $event): void
    {
        $fiber = Fiber::getCurrent();
        if (!$fiber) {
            return;
        }

        if (!isset($this->traces[$fiber])) {
            $this->traces[$fiber] = [];
        }

        $this->traces[$fiber][] = new EventTrace(
            $event,
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
            microtime(true)
        );
    }

    /**
     * @param \Ripple\Coroutine\Context $context
     *
     * @return array
     */
    public function getTraces(Context $context): array
    {
        return $this->traces[$context->fiber] ?? [];
    }

    /**
     * @param \Ripple\Coroutine\Context $context
     *
     * @return void
     */
    public function clear(Context $context): void
    {
        unset($this->traces[$context->fiber]);
    }
}
