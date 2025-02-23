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

use function microtime;
use function uniqid;
use function array_merge;

class Event
{
    /**
     * @var string
     */
    protected string $name;

    /**
     * @var float
     */
    protected float $timestamp;

    /**
     * @var array
     */
    protected array $context = [];

    /**
     * @var string|null
     */
    protected string|null $correlationId = null;

    /**
     * @param string $name
     * @param array  $context
     */
    public function __construct(string $name, array $context = [])
    {
        $this->name          = $name;
        $this->context       = $context;
        $this->timestamp     = microtime(true);
        $this->correlationId = uniqid('event_', true);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
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
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @return string|null
     */
    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * @param array $context
     *
     * @return $this
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * @return bool
     */
    public function handle(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isPropagationStopped(): bool
    {
        return false;
    }

    /**
     * @return void
     */
    public function stopPropagation(): void
    {
    }
}
