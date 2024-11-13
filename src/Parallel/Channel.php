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

class Channel
{
    /*** @param \parallel\Channel $channel */
    public function __construct(public readonly \parallel\Channel $channel)
    {
    }

    /**
     * @param mixed $value
     *
     * @return void
     */
    public function send(mixed $value): void
    {
        $this->channel->send($value);
    }

    /**
     * @return mixed
     */
    public function recv(): mixed
    {
        return $this->channel->recv();
    }

    /**
     * @return void
     */
    public function close(): void
    {
        $this->channel->close();
    }
}
