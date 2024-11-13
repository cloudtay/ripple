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

namespace Ripple\Parallel;

use Closure;
use parallel\Events;
use parallel\Events\Event;
use Ripple\Utils\Output;
use Throwable;

class Future
{
    /*** @var mixed */
    public mixed $result;

    /*** @var Closure */
    private Closure $onError;

    /*** @var Closure */
    private Closure $onValue;

    /**
     * @deprecated Should the user's active behavior be called back?？
     * @var Closure
     */
    private Closure $onCancelled;

    /**
     * @deprecated Should the user's active behavior be called back?？
     * @var Closure
     */
    private Closure $onKilled;

    /*** @param \parallel\Future $future */
    public function __construct(public readonly \parallel\Future $future)
    {
    }

    /**
     * @return bool
     */
    public function cancel(): bool
    {
        return $this->future->cancel();
    }

    /**
     * @return bool
     */
    public function done(): bool
    {
        return $this->future->done();
    }

    /**
     * @return void
     */
    public function cancelled(): void
    {
        $this->future->cancelled();
    }

    /**
     * @param Closure $onError
     *
     * @return Future
     */
    public function onError(Closure $onError): Future
    {
        $this->onError = $onError;
        return $this;
    }

    /**
     * @param Event $event
     *
     * @return void
     */
    public function onEvent(Events\Event $event): void
    {
        try {
            switch ($event->type) {
                case Events\Event\Type::Error:
                    if (isset($this->onError)) {
                        ($this->onError)($event->value);
                    }
                    break;

                case Events\Event\Type::Cancel:
                    if (isset($this->onCancelled)) {
                        ($this->onCancelled)($event->value);
                    }
                    break;
                case Events\Event\Type::Kill:
                    if (isset($this->onKilled)) {
                        ($this->onKilled)($event->value);
                    }
                    break;
            }
        } catch (Throwable $exception) {
            Output::error($exception->getMessage());
        }

    }

    /**
     * @param Closure $onValue
     *
     * @return $this
     */
    public function onValue(Closure $onValue): Future
    {
        $this->onValue = $onValue;
        return $this;
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function resolve(): void
    {
        if (isset($this->onValue)) {
            ($this->onValue)($this->result = $this->value());
        }
    }

    /**
     * @return mixed
     * @throws Throwable
     */
    public function value(): mixed
    {
        if (isset($this->result)) {
            return $this->result;
        }
        return $this->result = $this->future->value();
    }

    /**
     * @param Closure $onKilled
     *
     * @return Future
     * @deprecated Should the user's active behavior be called back?？
     */
    public function onKilled(Closure $onKilled): Future
    {
        $this->onKilled = $onKilled;
        return $this;
    }

    /**
     * @param Closure $onCancelled
     *
     * @return Future
     * @deprecated Should the user's active behavior be called back?？
     */
    public function onCancelled(Closure $onCancelled): Future
    {
        $this->onCancelled = $onCancelled;
        return $this;
    }
}
