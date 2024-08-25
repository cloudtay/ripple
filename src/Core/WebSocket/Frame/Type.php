<?php

namespace Psc\Core\WebSocket\Frame;

class Type
{
    const TEXT = 0x1;

    const BINARY = 0x2;

    const CLOSE = 0x8;

    const PING = 0x9;

    const PONG = 0xa;
}