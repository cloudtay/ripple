<?php declare(strict_types=1);

namespace Psc\Library\IO\Lock;

use Psc\Core\LibraryAbstract;

class LockLibrary extends LibraryAbstract
{
    /**
     * @var LibraryAbstract
     */
    protected static LibraryAbstract $instance;

    /**
     * @param string $name
     * @return Lock
     */
    public function access(string $name = 'default'): Lock
    {
        return new Lock($name);
    }
}
