<?php

namespace Psc\Core\WebSocket;

class Options
{
    public function __construct(private readonly bool $pingPong = true)
    {
    }

    public function getPingPong(): bool
    {
        return $this->pingPong;
    }
}