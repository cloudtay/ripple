<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Ripple\Socket;
use Ripple\Utils\Output;

use function Co\go;
use function Co\wait;

$address  = 'tcp://127.0.0.1:3001';
$fileSize = 10 * 1024 * 1024;

$randString = \random_bytes($fileSize);

go(static function () use ($address, $randString) {
    try {
        $server = Socket::server($address);
        echo "Server listening on $address", \PHP_EOL;

        $server->waitForReadable();
        $client = $server->accept();
        $client->setBlocking(false);

        echo "Server: client connected, start sending...", \PHP_EOL;
        $client->write($randString);

        echo "Server: finished sending", \PHP_EOL;
        $client->close();
        $server->close();
    } catch (Throwable $exception) {
        Output::exception($exception);
    }
});

go(static function () use ($address, $fileSize, $randString) {
    \Co\sleep(1);

    $client = Socket::connect($address);
    $client->setBlocking(false);
    echo "Client: connected to server", \PHP_EOL;

    $result   = '';
    $received = 0;

    while (true) {
        $client->waitForReadable();
        $data = $client->read(8192);

        if ($data === '' && $client->eof()) {
            echo "Client: connection closed", \PHP_EOL;
            break;
        }

        $result   .= $data;
        $received += \strlen($data);

        echo "Client: received {$received}/{$fileSize} bytes", \PHP_EOL;
        \Co\sleep(0.1);
    }

    $client->close();

    if ($result === $randString) {
        echo "Test passed: data integrity verified.", \PHP_EOL;
    } else {
        echo "Test failed: data mismatch!", \PHP_EOL;
    }

    echo "Client: total received size = ", \strlen($result), \PHP_EOL;
});

wait();
