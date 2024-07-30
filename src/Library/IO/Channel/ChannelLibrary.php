<?php declare(strict_types=1);

namespace Psc\Library\IO\Channel;

use Exception;
use Psc\Core\LibraryAbstract;

class ChannelLibrary extends LibraryAbstract
{
    /**
     * @var LibraryAbstract
     */
    protected static LibraryAbstract $instance;

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
