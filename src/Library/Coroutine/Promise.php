<?php declare(strict_types=1);

namespace Psc\Library\Coroutine;

use Throwable;

use function P\await;

/**
 *
 */
class Promise extends \Psc\Core\Coroutine\Promise
{
    /**
     * @return mixed
     * @throws Throwable
     */
    public function await(): mixed
    {
        return await($this);
    }
}
