<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Ripple\Socket;
use Ripple\Stream\CloseEvent;
use Ripple\Stream\Exception\TransportException;
use Ripple\Stream\Exception\WriteClosedException;
use Ripple\Utils\Output;

use function Co\wait;

/**
 * Example demonstrating proper usage of the enhanced Stream API
 * 
 * This example shows:
 * 1. How to handle connection lifecycle events (onClose, onReadableEnd)
 * 2. Proper exception handling (catch TransportException, not ConnectionException)
 * 3. Half-close support for HTTP-like protocols
 */

try {
    $stream = Socket::connect('tcp://httpbin.org:80');
    $stream->setBlocking(false);
    
    // Register connection lifecycle events
    $stream->onClose(function (CloseEvent $event) {
        Output::info("Connection closed: {$event->reason->value} by {$event->initiator}");
    });
    
    $stream->onReadableEnd(function () use ($stream) {
        Output::info("Read side closed - server finished sending response");
        // We can still write if needed, but in HTTP we typically close now
        $stream->close();
    });
    
    // Send HTTP request
    $request = "GET /get HTTP/1.1\r\nHost: httpbin.org\r\nConnection: close\r\n\r\n";
    $stream->write($request);
    
    // Read response
    $stream->onReadable(function () use ($stream) {
        try {
            $data = $stream->read(1024);
            if ($data !== '') {
                echo $data;
            }
            // Note: When server closes connection, onReadableEnd will be triggered
            // instead of an exception, allowing graceful handling
        } catch (WriteClosedException $e) {
            // This is a recoverable exception - safe to catch
            Output::warning("Attempted to write to closed connection: {$e->getMessage()}");
        } catch (TransportException $e) {
            // This is a recoverable exception - safe to catch
            Output::error("Transport error: {$e->getMessage()}");
            $stream->close();
        }
        // Note: We NEVER catch ConnectionException - that's handled internally by the reactor
    });
    
} catch (TransportException $e) {
    // Connection establishment failed - this is recoverable
    Output::error("Failed to connect: {$e->getMessage()}");
    exit(1);
}

wait();