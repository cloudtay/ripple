### Install

```bash
composer require cloudtay/p-ripple-core
```

### Example

```php
use GuzzleHttp\Psr7\Response;

include_once __DIR__ . '/vendor/autoload.php';

/**
 * you can use async function like this
 * \P\{Mod}()...->{Mod}()->{Method}();
 *
 * The method starting with the big camel case points to the submodule
 * The method at the beginning of the camel case points to the function
 *
 *
 * 你可以像这样使用异步函数
 * \P\{Mod}()...->{Mod}()->{Method}();
 *
 * 大驼峰开头的方法指向子模块
 * 驼峰式开头的方法指向函数
 */
P\IO();
P\IO()->File();
P\IO()->File()->getContents(__FILE__); //返回Promise对象

P\Net()->Http();
P\Net()->Http()->Guzzle();
P\Net()->Http()->Guzzle()->getAsync('https://www.baidu.com/404'); //返回Promise对象


P\async(function () {
    $fileContent = P\await(
        P\IO()->File()->getContents(__FILE__)
    );

    $hash = hash('sha256', $fileContent);
    echo "[await] File content hash: {$hash}" . PHP_EOL;
});

P\async(function () {
    try {
        $response = P\await(P\Net()->Http()->Guzzle()->getAsync('https://www.baidu.com/'));
        echo "[await] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    } catch (Throwable $exception) {
        echo "[await] Exception: {$exception->getMessage()}" . PHP_EOL;
    }
});

P\async(function () {
    P\Net()->Http()->Guzzle()->getAsync('https://www.baidu.com/')->then(function (Response $response) {
        echo "[async] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    })->except(function (Exception $e) {
        echo "[async] Exception: {$e->getMessage()}" . PHP_EOL;
    });
});

P\run(1000000);
```
