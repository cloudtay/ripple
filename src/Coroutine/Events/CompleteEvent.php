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

class CompleteEvent extends Event
{
    /*** @var Context */
    public readonly Context $coroutineContext;

    /*** @var mixed */
    public readonly mixed $result;

    /**
     * @param Context $coroutineContext
     * @param mixed  $result
     */
    public function __construct(Context $coroutineContext, mixed $result)
    {
        parent::__construct('coroutine.complete', [
            'coroutineContext' => $coroutineContext,
            'result' => $result
        ]);
        $this->coroutineContext = $coroutineContext;
        $this->result = $result;
    }

    /**
     * @return mixed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }
}
