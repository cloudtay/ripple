### Install

```bash
composer require cloudtay/p-ripple-core
```

### Example

```php
use GuzzleHttp\Psr7\Response;
use function P\async;
use function P\await;
use function P\run;

include_once __DIR__ . '/vendor/autoload.php';

# 支持库使用例子
P\IO::File();
P\IO::File()->getContents(__FILE__);
P\IO::Socket()->streamSocketClient('tcp://www.baidu.com:80');
P\IO::Socket()->streamSocketClientSSL('tcp://www.baidu.com:443');
P\Net::Http();
P\Net::Http()->Guzzle();
P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/404');
P\Net::WebSocket()->connect('ws://127.0.0.1:8001');

# 异步模式书写例子
// TODO: 异步发起100个请求
for ($i = 0; $i < 100; $i++) {
    P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/')->then(function (Response $response) {
        echo "[async] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    })->except(function (Exception $e) {
        echo "[async] Exception: {$e->getMessage()}" . PHP_EOL;
    });
}


# 同步模式书写例子
async(function () {
    $fileContent = await(
        P\IO::File()->getContents(__FILE__)
    );

    $hash = hash('sha256', $fileContent);
    echo "[await] File content hash: {$hash}" . PHP_EOL;
});

// TODO: 同步模式等待响应1, 请求过程不堵塞进程
async(function () {
    try {
        $response = await(P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/'));
        echo "[await] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    } catch (Throwable $exception) {
        echo "[await] Exception: {$exception->getMessage()}" . PHP_EOL;
    }
});

// TODO: 同步模式等待响应2, 请求过程不堵塞进程
async(function () {
    try {
        $response = await(P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/'));
        echo "[await] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    } catch (Throwable $exception) {
        echo "[await] Exception: {$exception->getMessage()}" . PHP_EOL;
    }
});

// TODO: WebSocket 链接例子
async(function () {
    try {
        $connection = await(P\Net::Websocket()->connect('wss://127.0.0.1:8001'));

        $connection->onMessage = function ($data) {
            echo 'Received: ' . $data . PHP_EOL;
        };

        $connection->onClose = function () {
            echo 'Connection closed' . PHP_EOL;
        };
    } catch (Throwable $exception) {
        echo "[await] 意料之外的连接失败: {$exception->getMessage()}" . PHP_EOL;
    }
});

run();
```

### More

本项目的异步遵循Promise规范 ,IO操作依赖`Psc\Core\Stream\Stream`开发,遵循`PSR-7`
标准,深入了解请参考`StreamInterface`、`PromiseInterface`等接口定义.
尽可能保证支持库的原始性,不做过多封装,以便于用户自定义扩展.
