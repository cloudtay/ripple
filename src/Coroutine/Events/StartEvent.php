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

class StartEvent extends Event
{
    /*** @var Context */
    public readonly Context $coroutineContext;

    /**
     * @param Context $coroutineContext
     */
    public function __construct(Context $coroutineContext)
    {
        parent::__construct('coroutine.start', ['coroutineContext' => $coroutineContext]);
        $this->coroutineContext = $coroutineContext;
    }
}
