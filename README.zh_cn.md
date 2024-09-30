<p align="center">
<img src="https://www.cloudtay.com/static/image/logo-wide.png" width="420" alt="Logo">
</p>
<p align="center">
<a href="#"><img src="https://img.shields.io/badge/PHP-%3E%3D%208.1-blue" alt="Build Status"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/dt/cclilshy/p-ripple-core" alt="Download statistics"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/v/cclilshy/p-ripple-core" alt="Stable version"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/l/cclilshy/p-ripple-core" alt="License"></a>
</p>
<p>
ripple是一个现代化的、高性能的原生PHP协程引擎, 旨在解决PHP在高并发、复杂网络通信和数据操作方面的挑战。
该引擎采用创新的架构和高效的编程模型, 为现代 Web 和 Web 应用程序提供强大而灵活的后端支持。
通过使用 ripple, 你将体验到从系统全局视图管理任务并高效处理网络流量和数据的优势。 </p>

## 设计哲学

极致的性能不是我们的主导方向

是`Event`机制赋予了PHP火箭般的性能, 我们则为`Event`提供了最佳实践规范

随着`PHP8`引入更轻量级的`Fiber`取代了`Generator`的协程模型,

我们的设计理念得以通过PHP自举的方式实现,同时我们使用了`revolt`作为ripple的底层驱动库, 使得ripple完美兼容原有的PHP生态

彻底解放PHPer的双手, 无缝拥抱全新的PHP协程时代

### 🌟 群聊已开放加入~ 🌟

`🔥 交流群的大门已为各位先行者打开,加入ripple的交流群,一起探讨PHP协程的未来`

**`🎉 加入方式`** 通过以下方式添加作者微信即可加入交流群

| 微信二维码                                                                                                                |
|----------------------------------------------------------------------------------------------------------------------|
| <img src="https://raw.githubusercontent.com/cloudtay/ripple/refs/heads/main/assets/images/wechat.jpg" width="380" /> |

## 安装

````bash
composer require cclilshy/p-ripple-core
````

## 基础用法

ripple严格遵循最新强类型的编程规范, 对IDE非常友好  
下述的复现过程在任何IDE中都能得到完美的支持和解释

### 最新文档

你可以访问ripple的[文档](https://p-ripple.cloudtay.com/)开始阅读

我们建议你从[手动安装](https://p-ripple.cloudtay.com/docs/install/professional)开始, 便于更好地理解ripple的工作流程

如果你想快速部署并使用ripple的服务, 你可以直接访问[快速部署](https://p-ripple.cloudtay.com/docs/install/server)

### 协程

> 通过`Co`类的`async`方法创建协程, 通过`Co`类的`sleep`方法模拟IO操作

```php
\Co\async(static function (){
    \Co\sleep(1);
    
    echo 'Coroutine 1' , PHP_EOL;
});

\Co\async(static function (){
    \Co\sleep(1);
    
    echo 'Coroutine 2' , PHP_EOL;
});

\Co\async(static function (){
    \Co\sleep(1);
    
    echo 'Coroutine 3' , PHP_EOL;
});

\Co\sleep(2); // 等待所有协程执行完毕
```

### HTTP客户端

> 通过`Guzzle`创建HTTP协程客户端, 已完美支持代理、重定向、超时、上传下载等功能

```php
use GuzzleHttp\Exception\GuzzleException;
use Psc\Utils\Output;

$client = Co\Plugin::Guzzle()->newClient();

for ($i = 0; $i < 10; $i++) {
    \Co\async(static function (){
        try {
            $response = $client->get('https://www.google.com/');
            echo $response->getStatusCode(), \PHP_EOL;
        } catch (GuzzleException $e) {
            Output::exception($e);
        }
    });
}

\Co\wait();
```

### HTTP客户端 - 使用SSE进行AI开发

> 以阿里云百炼为例, 通过SSE获取AI生成的文本, 一切如此简单

```php
use GuzzleHttp\Exception\GuzzleException;
use Psc\Core\Http\Client\Capture\ServerSentEvents;

if (!$key = $argv[1] ?? null) {
    echo 'Please enter the key' . \PHP_EOL;
    exit(1);
}

// Create interceptor
$sse = new ServerSentEvents();
$sse->onEvent(function ($event) {
    \var_dump($event);
});

// Refer to the documentation and ask questions
$client                  = Co\Plugin::Guzzle()->newClient();
$header                  = [];
$header['Content-Type']  = 'application/json';
$header['Accept']        = 'text/event-stream';
$header['Authorization'] = 'Bearer ' . $key;
$body                    = [
    'model' => 'qwen-max',
    'input' => [
        'messages' => [
            ['role' => 'system', 'content' => 'Your name is ripple knowledge base'],
            ['role' => 'user', 'content' => 'Who are you?'],
        ],
    ],
];

try {
    $response = $client->post('https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation', [
        'headers' => $header,
        'body'    => \json_encode($body, \JSON_UNESCAPED_UNICODE),

        'timeout'       => 10,

        // Injection interceptor
        'capture_write' => $sse->getWriteCapture(),
        'capture_read'  => $sse->getReadCapture(),
    ]);
} catch (GuzzleException $e) {
    echo $e->getMessage();
}
```

### HTTP服务端

> 通过`Co\Net`创建HTTP协程服务端, 通过`Co\Net`的`onRequest`方法处理请求

```php
use Psc\Core\Http\Server\Chunk;
use Psc\Core\Http\Server\Request;
use Psc\Core\Http\Server\Response;

$server = Co\Net::Http()->server('http://127.0.0.1:8008', \stream_context_create([
    'socket' => [
        'so_reuseport' => true,
        'so_reuseaddr' => true,
    ]
]));

$server->onRequest(static function (Request $request, Response $response) {
    switch (\trim($request->getRequestUri(), '/')) {
        case 'sse':
            $response->headers->set('Transfer-Encoding', 'chunked');
            $generator = static function () {
                foreach (\range(1, 10) as $i) {
                    Co\sleep(0.1);
                    yield Chunk::event('message', \json_encode(['id' => $i, 'content' => 'content']));
                }
                yield '';
            };
            $response->setContent($generator());
            $response->respond();
            break;
        default:
            $response->setContent('Hello, World!')->respond();
            break;
    }
});

$server->listen();

Co\wait();
```

### 更多

> 想了解WebSocket服务端与客户端、TCP服务端与客户端、UDP服务端与客户端、Unix服务端与客户端等等...

你可以访问ripple的[文档](https://p-ripple.cloudtay.com/)开始阅读

## 附录

### 适用组件库

> 我们允许用户自行选择适用的组件库, 所有组件只需像文档中描述的方式即可无需额外配置

**🚀 [Guzzle](https://docs.guzzlephp.org/en/stable/)**  
PHP应用最为广泛的HTTP客户端

**🔥 [AmPHP](https://amphp.org/)**  
提供丰富的PHP异步组件供用户自行封装

**🚀 [PDrive](https://github.com/cloudtay/p-ripple-drive)**  
官方提供的高性能驱动库，无缝接入你的传统应用

**🚀 [Webman-coroutine](https://github.com/workbunny/webman-coroutine)**
workbunny团队体统的webman协程扩展, 为Webman提供了协程支持

**🟢 [ripple](https://github.com/cloudtay/p-ripple-core)**  
提供标准的协程架构与工具用于迅速开发或封装传统应用

### 事件库指南

|  扩展类型   | 推荐使用 | 兼容性 |              说明               |
|:-------:|:----:|:---:|:-----------------------------:|
| `libev` | 🏅️  | 🟢️ | `Ev`是更加高效的事件扩展,在各系统中表现一致,推荐使用 |
|  `原生`   |  ️   | 🟢  |       支持PHP内置select机制使用       |
| `event` |      | 🌗  |     在不同系统下的事件特性不统一,不推荐使用      |

### Ev扩展安装

```bash
pecl install ev
```

## 特别致谢

<a href="https://www.jetbrains.com/?from=p-ripple-core" target="__blank">
    <img src="https://www.jetbrains.com/company/brand/img/jetbrains_logo.png" width="200">
</a>

[Jetbrains](https://www.jetbrains.com/?from=p-ripple-core) 为本项目提供了免费的开发工具

### 联系方式

`电邮` jingnigg@gmail.com

`微信` jingnigg

---
