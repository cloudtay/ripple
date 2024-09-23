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
Ripple is a modern, high-performance native PHP coroutine engine designed to solve PHP's challenges in high concurrency, complex network communication and data operations.
The engine uses an innovative architecture and efficient programming model to provide powerful and flexible backend support for modern web and web applications.
By using Ripple, you will experience the advantages of managing tasks from a global view of the system and efficiently handling network traffic and data. </p>

## Design Philosophy

Extreme performance is not our leading direction

It is the `Event` mechanism that gives PHP rocket-like performance, and we provide best practices for `Event`

With the introduction of `PHP8`, the more lightweight `Fiber` replaces the `Generator` coroutine model,

Our design concept can be realized through PHP bootstrapping. At the same time, we use `revolt` as the underlying driver
library of Ripple, making Ripple perfectly compatible with the original PHP ecosystem.

Completely free the hands of PHPer and seamlessly embrace the new era of PHP coroutines

## Install

````bash
composer require cclilshy/p-ripple-core
````

## Basic usage

Ripple strictly follows the latest strongly typed programming standards and is very friendly to IDEs
The following reproduction process is perfectly supported and explained in any IDE

### Latest documentation

You can visit Ripple’s [Documentation](https://p-ripple.cloudtay.com/) to start reading

We recommend that you start with [Manual Installation](https://p-ripple.cloudtay.com/docs/install/professional) to
better understand Ripple’s workflow

If you want to quickly deploy and use Ripple's services, you can directly
visit [Quick Deployment](https://p-ripple.cloudtay.com/docs/install/server)

### Coroutine

> Create coroutines through the `async` method of the `Co` class, and simulate IO operations through the `sleep` method
> of the `Co` class

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

\Co\sleep(2); // Wait for all coroutines to complete execution
```

### HTTP client

> Create HTTP coroutine client through `Guzzle`, which perfectly supports functions such as proxy, redirection, timeout,
> upload and download, etc.

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

### HTTP Client - AI development using SSE

> Taking Alibaba Cloud Bailian as an example, obtaining AI-generated text through SSE is so simple.

```php
use GuzzleHttp\Exception\GuzzleException;
use Psc\Core\Http\Client\Capture\ServerSentEvents;

if (!$key = $argv[1] ?? null) {
    echo 'Please enter the key' .\PHP_EOL;
    exit(1);
}

//Create interceptor
$sse = new ServerSentEvents();
$sse->onEvent(function ($event) {
    \var_dump($event);
});

// Refer to the documentation and ask questions
$client = Co\Plugin::Guzzle()->newClient();
$header = [];
$header['Content-Type'] = 'application/json';
$header['Accept'] = 'text/event-stream';
$header['Authorization'] = 'Bearer ' . $key;
$body = [
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
        'body' => \json_encode($body, \JSON_UNESCAPED_UNICODE),

        'timeout' => 10,

        //Injection interceptor
        'capture_write' => $sse->getWriteCapture(),
        'capture_read' => $sse->getReadCapture(),
    ]);
} catch (GuzzleException $e) {
    echo $e->getMessage();
}
```

### HTTP server

> Create an HTTP coroutine server through `Co\Net`, and process requests through the `onRequest` method of `Co\Net`

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

### More

> Want to know about WebSocket server and client, TCP server and client, UDP server and client, Unix server and client,
> etc...

You can visit Ripple’s [Documentation](https://p-ripple.cloudtay.com/) to start reading

## Appendix

### Applicable component library

> We allow users to choose applicable component libraries by themselves. All components can be used as described in the
> document without additional configuration.

**🚀 [Guzzle](https://docs.guzzlephp.org/en/stable/)**
PHP is the most widely used HTTP client

**🔥[AmPHP](https://amphp.org/)**
Provides rich PHP asynchronous components for users to encapsulate by themselves

**🟢[PDrive](https://github.com/cloudtay/p-ripple-drive)**
The official high-performance driver library provides seamless access to your traditional applications.

**🟢[Ripple](https://github.com/cloudtay/p-ripple-core)**
Provides standard coroutine architecture and tools for rapid development or packaging of traditional applications

### Event Library Guide

| Extension Types | Recommended Use | Compatibility |                                                     Description                                                      |
|:---------------:|:---------------:|:-------------:|:--------------------------------------------------------------------------------------------------------------------:|
|     `libev`     |       🏅️       |      🟢️      | `Ev` is a more efficient event extension that performs consistently in various systems and is recommended to be used |
|    `Native`     |        ️        |      🟢       |                                  Support the use of PHP's built-in select mechanism                                  |
|     `event`     |                 |      🌗       |         The event characteristics under different systems are not uniform, and their use is not recommended          |

### Ev extension installation

```bash
pecl install ev
```

## Special thanks

<a href="https://www.jetbrains.com/?from=p-ripple-core" target="__blank">
    <img src="https://www.jetbrains.com/company/brand/img/jetbrains_logo.png" width="200">
</a>

[Jetbrains](https://www.jetbrains.com/?from=p-ripple-core) provides free development tools for this project

### Contact information

`Email` jingnigg@gmail.com
