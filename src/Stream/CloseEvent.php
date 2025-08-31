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

namespace Ripple\Stream;

/**
 * Event object containing information about connection closure
 */
readonly class CloseEvent
{
    public function __construct(
        public ConnectionAbortReason $reason,
        public string $initiator, // 'peer' | 'local' | 'system'
        public ?string $message = null,
        public ?\Throwable $lastError = null,
        public int $timestamp = null
    ) {
        $this->timestamp = $timestamp ?? time();
    }
}