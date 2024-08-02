<?php declare(strict_types=1);

namespace P;

use Psc\Plugins\Guzzle\Guzzle;

class Plugin
{
    public static function Guzzle(): Guzzle
    {
        return Guzzle::getInstance();
    }
}
