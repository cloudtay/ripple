# Stream Exception Handling Guide

## Overview

The Stream module uses a clear exception hierarchy that separates internal control-flow exceptions from application-level exceptions that user code should handle.

## Exception Hierarchy

```text
RuntimeException
├── StreamException (base for user-catchable exceptions)
│   └── TransportException (recoverable transport errors)
│       ├── TransportTimeoutException (timeout errors)
│       ├── ConnectionTimeoutException (connection timeouts)
│       └── WriteClosedException (write to closed stream)
└── ConnectionException (internal control-flow - DO NOT CATCH)
```

## Key Principles

### 1. Internal Control-Flow Exceptions (DO NOT CATCH)

**ConnectionException** is an internal exception used exclusively by the reactor for connection termination. It implements the `AbortConnection` marker interface.

- **Purpose**: Signal immediate connection termination to the reactor
- **Usage**: Only thrown internally when connection becomes unusable
- **Handling**: Only caught by reactor's exception boundary, never by user code

```php
// ❌ NEVER do this
try {
    $stream->read(1024);
} catch (ConnectionException $e) {
    // This breaks the reactor's control flow!
}

// ✅ Use connection lifecycle events instead
$stream->onClose(function (CloseEvent $event) {
    echo "Connection closed: {$event->reason->value}";
});
```

### 2. Application-Level Exceptions (Safe to Catch)

**TransportException** and its subclasses represent recoverable errors that application code can handle.

```php
// ✅ Safe to catch and handle
try {
    $stream->write($data);
} catch (WriteClosedException $e) {
    // Write side is closed, but connection might still be readable
    echo "Cannot write: {$e->getMessage()}";
} catch (TransportException $e) {
    // Other transport-level errors
    echo "Transport error: {$e->getMessage()}";
}
```

## Connection Lifecycle Events

Instead of catching exceptions, use lifecycle events to handle connection state changes:

### onClose(CloseEvent)

Triggered once when the connection terminates.

```php
$stream->onClose(function (CloseEvent $event) {
    echo "Closed: {$event->reason->value} by {$event->initiator}";
    // Cleanup resources, update connection pools, etc.
});
```

**CloseEvent** provides:

- `reason`: ConnectionAbortReason enum (PEER_CLOSED, RESET, TIMEOUT, etc.)
- `initiator`: 'peer' | 'local' | 'system'
- `message`: Optional descriptive message
- `lastError`: Optional underlying exception
- `timestamp`: When the close occurred

### onReadableEnd()

Triggered when the read side closes (EOF) but connection may still be writable.

```php
$stream->onReadableEnd(function () use ($stream) {
    echo "Read side closed - no more data from peer";
    // Can still write final response, then close
    $stream->write("HTTP/1.1 200 OK\r\n\r\nGoodbye");
    $stream->close();
});
```

### onWritableEnd()

Triggered when the write side closes but connection may still be readable.

```php
$stream->onWritableEnd(function () use ($stream) {
    echo "Write side closed - cannot send more data";
    // Can still read remaining data from peer
});
```

## Half-Close Support

Half-close allows one side of a connection to be closed while the other remains open. This is useful for protocols like HTTP where the client sends a complete request, then the server sends a complete response.

### Configuration

```php
$stream = new Stream($resource);
$stream->supportsHalfClose = true; // Default: true
```

### Behavior

When `supportsHalfClose = true`:

- `read()` returning EOF triggers `onReadableEnd()` if registered, otherwise throws `ConnectionException`
- `write()` getting EPIPE triggers `onWritableEnd()` if registered, otherwise throws `ConnectionException`

When `supportsHalfClose = false`:

- EOF or EPIPE immediately throws `ConnectionException` for reactor termination

## Error Classification

### Fatal Errors (→ ConnectionException)

These errors indicate the connection is no longer usable:

- Peer closed connection (EOF without half-close support)
- Connection reset by peer (ECONNRESET)
- TLS fatal alerts
- Broken pipe (EPIPE without half-close support)

### Recoverable Errors (→ TransportException)

These errors can be handled by application logic:

- Connection timeouts (can retry)
- Write to closed stream (can detect and handle)
- Protocol-level errors (application can decide response)
- Temporary resource unavailability

## Migration from Old API

### Before

```php
try {
    $data = $stream->read(1024);
} catch (ConnectionException $e) {
    if ($e->getCode() === ConnectionException::CONNECTION_CLOSED) {
        // Handle close
    }
}
```

### After

```php
// Use events for lifecycle management
$stream->onClose(function (CloseEvent $event) {
    if ($event->reason === ConnectionAbortReason::PEER_CLOSED) {
        // Handle close
    }
});

$stream->onReadableEnd(function () {
    // Handle EOF/half-close
});

// Only catch recoverable exceptions
try {
    $data = $stream->read(1024);
} catch (TransportException $e) {
    // Handle recoverable errors only
}
```

## Best Practices

1. **Never catch ConnectionException** - use lifecycle events instead
2. **Register onClose for cleanup** - guaranteed to be called once per connection
3. **Use onReadableEnd/onWritableEnd** for half-close protocols
4. **Catch TransportException** for recoverable error handling
5. **Let the reactor handle fatal errors** - it will clean up and emit events

## Common Patterns

### HTTP Server

```php
$stream->onReadableEnd(function () use ($stream, $response) {
    // Client finished sending request, send response
    $stream->write($response);
    $stream->close();
});

$stream->onClose(function (CloseEvent $event) use ($connectionPool) {
    $connectionPool->remove($stream);
});
```

### Database Client

```php
$stream->onClose(function (CloseEvent $event) use ($pendingQueries) {
    // Fail all pending queries
    foreach ($pendingQueries as $query) {
        $query->fail(new TransportException("Connection lost: {$event->reason->value}"));
    }
});
```

### WebSocket

```php
$stream->onClose(function (CloseEvent $event) use ($subscriptions) {
    // Clean up subscriptions
    foreach ($subscriptions as $sub) {
        $sub->cancel();
    }
});
```
