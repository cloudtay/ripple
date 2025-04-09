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

namespace Ripple\Coroutine\Events;

use Ripple\Coroutine\Context;
use Ripple\Coroutine\Event\Event;
use Throwable;

class ErrorEvent extends Event
{
    /**
     * @var Context
     */
    public readonly Context $coroutineContext;

    /**
     * @var Throwable
     */
    public readonly Throwable $error;

    /**
     * @param Context     $coroutineContext
     * @param Throwable $error
     */
    public function __construct(Context $coroutineContext, Throwable $error)
    {
        parent::__construct('coroutine.error', [
            'coroutineContext' => $coroutineContext,
            'error' => $error->getMessage()
        ]);
        $this->coroutineContext = $coroutineContext;
        $this->error = $error;
    }

    /**
     * @return Throwable
     */
    public function getError(): Throwable
    {
        return $this->error;
    }
}
