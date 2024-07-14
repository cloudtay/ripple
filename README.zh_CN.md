<p align="center">
<img src="https://www.cloudtay.com/static/image/logo-wide.png" width="420" alt="Logo">
</p>
<p align="center">
<a href="#"><img src="https://img.shields.io/badge/PHP-%3E%3D%208.3-blue" alt="Build Status"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/dt/cclilshy/p-ripple-core" alt="Download statistics"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/v/cclilshy/p-ripple-core" alt="Stable version"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/l/cclilshy/p-ripple-core" alt="License"></a>
</p>
<p>
PRipple是现代化的一个高性能的原生PHP协程框架，旨在解决PHP在高并发、复杂网络通信和数据操作方面的挑战。
该框架采用创新的架构和高效的编程模型，为现代 Web 和 Web 应用程序提供强大而灵活的后端支持。
通过使用 PRipple，你将体验到优势从系统的全局视图管理任务并有效处理网络流量和数据。</p>

<p align="center">
    <a href="https://github.com/cloudtay/p-ripple-core">English</a>
    ·
    <a href="https://github.com/cloudtay/p-ripple-core/blob/main/README.zh_CN.md">简体中文</a>
    ·
    <a href="https://github.com/cloudtay/p-ripple-core/issues">报告错误 »</a>
</p>

---

## 安装

```bash
# 安装最佳兼容性依赖 (LibEvent/Select/UV 可能达不到最佳效果)
pecl install ev

# 安装PRipple
composer require cclilshy/p-ripple-core
```

---

## 用法

> PRipple所有工具由Store提供, Store是一个全局的工具集合, 命名空间为`P\{Module}`
> ,你可以通过Store获取到所有的工具,如`P\Net`、`P\IO`、`P\System`等

### 例子

```php
\P\IO::File();
\P\IO::Socket();
\P\Net::Http();
\P\Net::WebSocket();
\P\System::Process();
```

### 基本使用

> PRipple还提供了基本语法糖,如`async`、`await`等,用于简化异步编程如

```php
# Promise异步模式
$promise1 = \P\promise($r,$d)->then(function(){
    $r('done');
});

# async/await异步模式
$promise2 = \P\async(function($r,$d){
    \P\sleep(1);
    
    $r('done2');
});

\P\async(function() use ($promise1, $promise2){
    $result1 = await($promise1);
    $result2 = await($promise2);
    echo 'result1: ' . $result1 . PHP_EOL;
    echo 'result2: ' . $result2 . PHP_EOL;
});

/**
 * Timer相关
 */
# 重复做某事直到cancel被调用
$timerId = \P\repeat(1, function(Closure $cancel){
    echo 'repeat' . PHP_EOL;
});

# 取消Timer行为
\P\cancel($timerId);

# 在指定时间后执行某事
$timerId = \P\delay(1, function(){
    echo 'after' . PHP_EOL;
});

# 在发生前取消delay声明
\P\cancel($timerId);
```

### File 模块

> ⚠️该模块依赖于`kqueue`机制的Event处理器,epoll机制下的事件处理器不支持对文件流的监听,
> 在OSX中`lib-event`使用的是`kqueue`, 在Linux中使用的是`epoll`, 在Windows中使用的是`select`, 请确保你的系统支持`kqueue`
> 机制,

> 为达到高水准的兼容性, 请安装`Ev`扩展

#### 安装 Ev 扩展

```bash
pecl install ev
```

```php
# 读取文件内容
async(function () {
    $fileContent = await(
        P\IO::File()->getContents(__FILE__)
    );

    $hash = hash('sha256', $fileContent);
    echo "[await] File content hash: {$hash}" . PHP_EOL;
});

# 写入文件内容
P\IO::File()->getContents(__FILE__)->then(function ($fileContent) {
    $hash = hash('sha256', $fileContent);
    echo "[async] File content hash: {$hash}" . PHP_EOL;
});

# 打开文件
$stream = P\IO::File()->open(__FILE__, 'r');
```

### Socket 模块

```php
# 建立一个Socket连接
/**
 * @param string $uri
 * @param int $flags
 * @param null $context
 * @return Promiss<SocketStream>
 */
P\IO::Socket()->streamSocketClient('tcp://www.baidu.com:80');

# 建立一个SSL Socket连接
P\IO::Socket()->streamSocketClientSSL('tcp://www.baidu.com:443');
```

### Net 模块

```php
# 异步发起100个请求,方式1
for ($i = 0; $i < 100; $i++) {
    P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com/')->then(function (Response $response) {
        echo "[async] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    })->except(function (Exception $e) {
        echo "[async] Exception: {$e->getMessage()}" . PHP_EOL;
    });
}

# 异步发起100个请求,方式2
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

# WebSocket 连接
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

### Parallel 模块

#### 说明

> PRipple提供了 runtime 的并行运行支持,依赖于多进程,抽象了多进程的细节,使用者只需要关心并行运行的使用方式 ,
> 你可以在闭包中使用几乎所有的PHP语句, 但依然存在需要注意的事项

#### 注意事项

* 在子进程中,所有上下文资源都继承了父进程的资源,包括已打开的文件,已连接的数据库,已连接的网络,全局变量
* 所有定义的Event如`onRead`、`onWrite`等都会在子进程中被遗忘
* 你不能在async中创建子进程如

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

### Http服务 模块

#### 说明

> PRipple提供了一个简单的HttpServer,可以用于快速搭建一个简单的Http服务器,使用方法如下

#### 简介

> 其中Request和Response继承并实现了`Symfony`的`RequestInterface`和`ResponseInterface`接口规范
> 可以像使用Symfony / Laravel中的 HttpFoundation 组件一样使用他们

#### 用例

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

> PRipple支持配合Parallel的模块实现端口多路复用

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

## 标准API

- Stream (暂定)
- SocketStream (暂定)
- Promise (暂定)
- ...

## 扩展

- [Workerman集成方法](https://github.com/cloudtay/p-ripple-drive.git)
- [Webman集成方法](https://github.com/cloudtay/p-ripple-drive.git)
- [Laravel集成方法](https://github.com/cloudtay/p-ripple-drive.git)
- [ThinkPHP集成方法](https://github.com/cloudtay/p-ripple-drive.git)

## 更多

PRipple的异步遵循Promise规范,IO操作依赖`Psc\Core\Stream\Stream`开发遵循`PSR-7`
标准,深入了解请参考`StreamInterface`、`PromiseInterface`等接口定义
尽可能保证支持库的原始性,不做过多封装,以便于用户自定义扩展

## 附言

欢迎各位开发者尝鲜,本人征集更多的意见和建议,欢迎提交PR或者Issue,我们会尽快处理<br>
本项目处于alpha阶段,可能会有不稳定的地方,请谨慎在生产环境中使用<br>

联系方式: jingnigg@gmail.com

### 相关项目

- RevoltPHP: [https://revolt.run/](https://revolt.run/)
- Workerman/Webman: [https://www.workerman.net/](https://www.workerman.net/)
- Laravel: [https://laravel.com/](https://laravel.com/)
- ThinkPHP: [https://www.thinkphp.cn/](https://www.thinkphp.cn/)
- Symfony: [https://symfony.com/](https://symfony.com/)
- PHP: [https://www.php.net/](https://www.php.net/)
- JavaScript: [https://www.javascript.com/](https://www.javascript.com/)

### 鸣谢

- Jetbrains: [https://www.jetbrains.com/](https://www.jetbrains.com/)
- OpenAI: [https://www.openai.com/](https://www.openai.com/)
- W3C: [https://www.w3.org/](https://www.w3.org/)
