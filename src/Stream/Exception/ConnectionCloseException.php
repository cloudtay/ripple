<?php declare(strict_types=1);

namespace Ripple\Stream\Exception;

use Psr\Http\Message\StreamInterface;
use Throwable;

class ConnectionCloseException extends ConnectionException
{
    public function __construct(string $message = "", Throwable|null $previous = null, StreamInterface|null $streamBase = null)
    {
        parent::__construct(
            $message,
            ConnectionException::CONNECTION_CLOSED,
            $previous,
            $streamBase
        );
    }
}
