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

namespace Ripple\Event;

use function array_map;

class EventTrace
{
    /**
     * @var \Ripple\Coroutine\Event\Event
     */
    private Event $event;

    /**
     * @var array
     */
    private array $trace;

    /**
     * @var float
     */
    private float $timestamp;

    /**
     * @param \Ripple\Coroutine\Event\Event $event
     * @param array                         $trace
     * @param float                         $timestamp
     */
    public function __construct(Event $event, array $trace, float $timestamp)
    {
        $this->event     = $event;
        $this->trace     = $trace;
        $this->timestamp = $timestamp;
    }

    /**
     * @return \Ripple\Coroutine\Event\Event
     */
    public function getEvent(): Event
    {
        return $this->event;
    }

    /**
     * @return array
     */
    public function getTrace(): array
    {
        return $this->trace;
    }

    /**
     * @return float
     */
    public function getTimestamp(): float
    {
        return $this->timestamp;
    }

    /**
     * @return array
     */
    public function getFormattedTrace(): array
    {
        return array_map(static function ($trace) {
            return [
                'file'     => $trace['file'] ?? null,
                'line'     => $trace['line'] ?? null,
                'function' => $trace['function'] ?? null,
                'class'    => $trace['class'] ?? null,
                'type'     => $trace['type'] ?? null
            ];
        }, $this->trace);
    }
}
