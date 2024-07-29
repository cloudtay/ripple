<?php declare(strict_types=1);

namespace Psc\Library\IO\Channel;

use Psc\Core\StoreAbstract;
use Exception;

class ChannelLibrary extends StoreAbstract
{
    /**
     * @var StoreAbstract
     */
    protected static StoreAbstract $instance;

    /**
     * @param string $name
     * @return Channel
     * @throws Exception
     */
    public function open(string $name): Channel
    {
        return Channel::open($name);
    }

    /**
     * @param string $name
     * @return Channel
     * @throws Exception
     */
    public function make(string $name): Channel
    {
        return Channel::make($name);
    }
}
