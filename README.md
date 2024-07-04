### Install

```bash
composer require cloudtay/p-ripple-core
```

### Example

```php
P\IO::File();
P\IO::File()->getContents(__FILE__);                              //返回Promise对象
P\IO::Socket()->streamSocketClient('tcp://www.baidu.com:80');     // 异步完成连接
P\IO::Socket()->streamSocketClientSSL('tcp://www.baidu.com:443'); //异步完成SSL握手

P\Net::Http();
P\Net::Http()->Guzzle();
P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/404'); //返回Promise对象

P\async(function () {
    $fileContent = P\await(
        P\IO::File()->getContents(__FILE__)
    );

    $hash = hash('sha256', $fileContent);
    echo "[await] File content hash: {$hash}" . PHP_EOL;
});

P\async(function () {
    try {
        $response = P\await(P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/'));
        echo "[await] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    } catch (Throwable $exception) {
        echo "[await] Exception: {$exception->getMessage()}" . PHP_EOL;
    }
});

P\async(function () {
    P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/')->then(function (Response $response) {
        echo "[async] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    })->except(function (Exception $e) {
        echo "[async] Exception: {$e->getMessage()}" . PHP_EOL;
    });
});

P\run();
```
