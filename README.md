### Install

```bash
composer require cclilshy/p-ripple-core
```

---
### Example

```php
use GuzzleHttp\Psr7\Response;
use P\Net\WebSocket\Connection;
use function P\async;
use function P\await;
use function P\repeat;
use function P\run;

# 支持库使用例子
P\IO::File();
P\IO::File()->getContents(__FILE__);
P\IO::Socket()->streamSocketClient('tcp://www.baidu.com:80');
P\IO::Socket()->streamSocketClientSSL('tcp://www.baidu.com:443');
P\Net::Http();
P\Net::Http()->Guzzle();
P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/404');

# 效果展示
// TODO: 异步发起100个请求,方式1
for ($i = 0; $i < 100; $i++) {
    P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/')->then(function (Response $response) {
        echo "[async] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    })->except(function (Exception $e) {
        echo "[async] Exception: {$e->getMessage()}" . PHP_EOL;
    });
}

// TODO: 异步发起100个请求,方式2
for ($i = 0; $i < 100; $i++) {
    async(function () {
        try {
            $response = await(P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/'));
            echo "[await] Response status code: {$response->getStatusCode()}" . PHP_EOL;
        } catch (Throwable $exception) {
            echo "[await] Exception: {$exception->getMessage()}" . PHP_EOL;
        }
    });
}

// TODO: 异步读取文件内容
async(function () {
    $fileContent = await(
        P\IO::File()->getContents(__FILE__)
    );

    $hash = hash('sha256', $fileContent);
    echo "[await] File content hash: {$hash}" . PHP_EOL;
});

// TODO: WebSocket 链接例子
$connection         = P\Net::Websocket()->connect('wss://127.0.0.1:8001/wss');
$connection->onOpen = function (Connection $connection) {
    $connection->send('{"action":"sub","data":{"channel":"market:panel@8"}}');

    $timerId = repeat(10, function () use ($connection) {
        $connection->send('{"action":"ping","data":{}}');
    });

    $connection->onClose = function (Connection $connection) use ($timerId) {
        P\cancel($timerId);
    };

    $connection->onMessage = function (string $message, int $opcode, Connection $connection) {
        echo "receive: $message\n";
    };
};

$connection->onError = function (Throwable $throwable) {
    echo "error: {$throwable->getMessage()}\n";
};

run();
```

---

### Parallel

#### 说明

> p-ripple提供了runtime的并行运行支持,该功能依赖于多进程,但抽象了多进程的细节,使用者只需要关心并行运行的使用方式即可
> 你可以在闭包中使用几乎所有的PHP语句,在子进程中,所有上下文资源都继承了父进程的资源,包括已打开的文件,已连接的数据库,已连接的网络,全局变量,
> 但所有定义的Event如`onRead`、`onWrite`等都会在子进程中被遗忘

#### 注意事项

> 你不能在async中创建子进程如

```php
async(function(){
    $task = P\System::Process()->task(function(){
        // 子进程
    });
    $task->run();
});
```

> 这将会抛出一个异常 `ProcessException`

#### 用法

```php
$task = P\System::Process()->task(function(){
    sleep(10);
    
    exit(0);
});

$runtime = $task->run();                // 返回一个Runtime对象
$runtime->stop();                       // 取消运行(信号SIGTERM)
$runtime->stop(true);                   // 强制终止,等同于$runtime->kill()
$runtime->kill();                       // 强制终止(信号SIGKILL)
$runtime->signal(SIGTERM);              // 发送信号,提供了更精细的控制手段
$runtime->then(function($exitCode){});  // 程序正常退出时会触发这里的代码,code为退出码
$runtime->except(function(){});         // 程序非正常退出时会触发这里的代码,可以在这里处理异常,如进程守护/task重启
$runtime->finally(function(){});        // 无论程序正常退出还是非正常退出都会触发这里的代码
$runtime->getProcessId();               // 获取子进程ID
$runtime->getPromise();                 // 获取Promise对象
```

---
### Http Server

#### 说明

> PRipple提供了一个简单的HttpServer,可以用于快速搭建一个简单的HttpServer,使用方法如下
> 其中Request和Response继承并实现了`Symfony`的`RequestInterface`和`ResponseInterface`接口规范
> 可以像使用Symfony / Laravel中的 HttpFoundation 组件一样使用他们

#### 用法
```php
use P\IO;
use Psc\Store\Net\Http\Server\Request;
use Psc\Store\Net\Http\Server\Response;
use function P\await;
use function P\run;

$server = P\Net::Http()->server('http://127.0.0.1:8008');
$handler = function (Request $request, Response $response) {
    if ($request->getMethod() === 'POST') {
        $files = $request->files->get('file');
        $data  = [];
        foreach ($files as $file) {
            $data[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $file->getPathname(),
            ];
        }
        $response->headers->set('Content-Type', 'application/json');
        $response->setContent(json_encode($data));
        $response->respond();
    }

    if ($request->getMethod() === 'GET') {
        if ($request->getPathInfo() === '/') {
            $response->setContent('Hello World!');
            $response->respond();
        }

        if ($request->getPathInfo() === '/download') {
            $response->setContent(
                IO::File()->open(__FILE__, 'r')
            );
            $response->respond();
        }

        if ($request->getPathInfo() === '/upload') {
            $template = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Upload</title></head><body><form action="/upload" method="post" enctype="multipart/form-data"><input type="file" name="file"><button type="submit">Upload</button></form></body>';
            $response->setContent($template);
            $response->respond();
        }

        if ($request->getPathInfo() === '/qq') {
            $qq = await(P\Net::Http()->Guzzle()->getAsync(
                'https://www.qq.com/'
            ));

            $response->setContent($qq->getBody()->getContents());
            $response->respond();
        }
    }
};

$server->onRequest = $handler;
$server->listen();

run();
```

#### 端口复用

> PRipple支持配合Parallel的特性端口多路复用

```php
# 如上创建好HttpServer后,可以替代监听方式实现端口多路复用

$task = P\System::Process()->task( fn() => $server->listen() );

$task->run();   //runtime1
$task->run();   //runtime2
$task->run();   //runtime3
$task->run();   //runtime4
$task->run();   //runtime5

# 守护模式启动例子
$guardRun = function($task) use (&$guardRun){
    $task->run()->except(function() use ($task, &$guardRun){
        $guardRun($task);
    });
};
$guardRun($task);

P\run();
```

### 在你的应用中使用?

- [Workerman集成方法](https://github.com/cloudtay/p-ripple-drive.git)
- [Webman集成方法](https://github.com/cloudtay/p-ripple-drive.git)
- [Laravel集成方法](https://github.com/cloudtay/p-ripple-drive.git)

### More

PRipple的异步遵循Promise规范 ,IO操作依赖`Psc\Core\Stream\Stream`开发,遵循`PSR-7`
标准,深入了解请参考`StreamInterface`、`PromiseInterface`等接口定义
尽可能保证支持库的原始性,不做过多封装,以便于用户自定义扩展
