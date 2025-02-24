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

use Ripple\Utils\Output;
use Throwable;
use Closure;

use function Co\go;
use function uasort;
use function uniqid;
use function gc_collect_cycles;

class EventDispatcher
{
    /**
     * @var EventDispatcher|null
     */
    private static EventDispatcher|null $instance = null;

    /**
     * @var array<string,array<callable>>
     */
    private array $listeners = [];

    /**
     * @return EventDispatcher
     */
    public static function getInstance(): EventDispatcher
    {
        if (EventDispatcher::$instance === null) {
            EventDispatcher::$instance = new EventDispatcher();
        }
        return EventDispatcher::$instance;
    }

    /**
     * @param string  $eventName
     * @param Closure $listener
     * @param int     $priority
     *
     * @return string
     */
    public function addListener(string $eventName, Closure $listener, int $priority = 0): string
    {
        $id = uniqid('listener_', true);

        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }

        $this->listeners[$eventName][$id] = [
            'callable' => $listener,
            'priority' => $priority
        ];

        uasort($this->listeners[$eventName], function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        return $id;
    }

    /**
     * @param string $eventName
     * @param string $listenerId
     *
     * @return void
     */
    public function removeListener(string $eventName, string $listenerId): void
    {
        if (isset($this->listeners[$eventName][$listenerId])) {
            unset($this->listeners[$eventName][$listenerId]);
        }
    }

    /**
     * @param Event $event
     *
     * @return void
     */
    public function dispatch(Event $event): void
    {
        $eventName = $event->getName();

        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $listener) {
            if ($event->isPropagationStopped()) {
                break;
            }

            try {
                go($listener['callable'])($event);
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }

        gc_collect_cycles();
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        $this->listeners = [];
    }
}
