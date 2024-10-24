<?php declare(strict_types=1);

namespace Ripple\Stream\Exception;

use Ripple\Stream\StreamInterface;
use Throwable;

class ConnectionTimeoutException extends ConnectionException
{
    public function __construct(
        string          $message = "",
        Throwable       $previous = null,
        StreamInterface $streamBase = null,
        bool            $close = true
    ) {
        parent::__construct($message, ConnectionException::CONNECTION_TIMEOUT, $previous, $streamBase, $close);
    }
}
