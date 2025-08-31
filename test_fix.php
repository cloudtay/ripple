<?php declare(strict_types=1);

// Simple test to verify our fixes
require_once __DIR__ . '/vendor/autoload.php';

use Ripple\Stream\CloseEvent;
use Ripple\Stream\ConnectionAbortReason;
use Ripple\Stream\Exception\ConnectionException;

echo "Testing CloseEvent creation...\n";

try {
    // Test CloseEvent creation (this was causing the readonly property error)
    $closeEvent = new CloseEvent(
        ConnectionAbortReason::PEER_CLOSED,
        'peer',
        'Connection closed by peer'
    );
    echo "✅ CloseEvent created successfully\n";
    echo "   Reason: {$closeEvent->reason->value}\n";
    echo "   Initiator: {$closeEvent->initiator}\n";
    echo "   Timestamp: {$closeEvent->timestamp}\n";
} catch (Throwable $e) {
    echo "❌ CloseEvent creation failed: {$e->getMessage()}\n";
    echo "   File: {$e->getFile()}:{$e->getLine()}\n";
}

echo "\nTesting ConnectionException creation...\n";

try {
    // Test ConnectionException creation (internal use only)
    $exception = new ConnectionException(ConnectionAbortReason::RESET, 'Test reset');
    echo "✅ ConnectionException created successfully\n";
    echo "   Reason: {$exception->reason->value}\n";
    echo "   Message: {$exception->getMessage()}\n";
} catch (Throwable $e) {
    echo "❌ ConnectionException creation failed: {$e->getMessage()}\n";
}

echo "\nAll basic tests completed!\n";