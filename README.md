<p align="center">
<img src="https://www.cloudtay.com/static/image/logo-wide.png" width="420" alt="Logo">
</p>
<p align="center">
<a href="#"><img src="https://img.shields.io/badge/PHP-%3E%3D%208.1-blue" alt="Build Status"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/dt/cclilshy/p-ripple-core " alt="Download statistics"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/v/cclilshy/p-ripple-core " alt="Stable version"></a>
<a href="https://packagist.org/packages/cclilshy/p-ripple-core"><img src="https://img.shields.io/packagist/l/cclilshy/p-ripple-core " alt="License"></a>
</p>
<p>
PRipple is a modern, high-performance native PHP coroutine engine designed to solve PHP's challenges in high concurrency, complex network communication and data operations.
The engine uses an innovative architecture and efficient programming model to provide powerful and flexible backend support for modern web and web applications.
By using PRipple, you will experience the advantages of managing tasks from a global view of the system and efficiently handling network traffic and data. </p>

## Design Philosophy

> The coroutine model of the `Go` language is the source of design inspiration for PRipple. With the introduction
> of `PHP8`, the more lightweight `Fiber` replaced the `Generator` coroutine model.
> Our design concept can be realized through PHP bootstrapping, realizing a high-performance coroutine engine. At the
> same time, we used `revolt` as the underlying driver library of PRipple.
> Makes PRipple perfectly compatible with the original PHP ecosystem

### Applicable component library

> We allow users to choose applicable component libraries by themselves. All components can be used as described in the
> document without additional configuration.

**🚀 [Guzzle](https://docs.guzzlephp.org/en/stable/)** PHP is the most widely used HTTP client

**🔥 [AmPHP](https://amphp.org/)** Provides rich PHP asynchronous components for users to encapsulate by themselves

**🟢 [PDrive](https://github.com/cloudtay/p-ripple-drive)** The official high-performance driver library provides
seamless access to your traditional applications.

**🟢 [PRipple](https://github.com/cloudtay/p-ripple-core)** Provides standard coroutine architecture and tools for rapid
development or packaging of traditional applications

**More** 🌗

### Event Library Guide

| Extension Types | Recommended Use | Compatibility |                                                     Description                                                      |
|:---------------:|:---------------:|:-------------:|:--------------------------------------------------------------------------------------------------------------------:|
|     `libev`     |       🟢        |      🟢️      | `Ev` is a more efficient event extension that performs consistently in various systems and is recommended to be used |
|    `Native`     |        ️        |      🟢       |                                  Support the use of PHP's built-in select mechanism                                  |
|     `event`     |                 |      🌗       |          The event characteristics under different systems are not uniform, and its use is not recommended           |

## Install

````bash
composer require cclilshy/p-ripple-core
````

### Ev extension installation

```bash
pecl install ev
```

### Start learning

You can visit PRipple’s [Documentation](https://p-ripple.cloudtay.com/) to start reading

We recommend that you start with [Manual Installation](https://p-ripple.cloudtay.com/docs/install/professional) to
better understand PRipple’s workflow

If you want to quickly deploy and use PRipple's services, you can directly
visit [Quick Deployment](https://p-ripple.cloudtay.com/docs/install/server)

## Special thanks

<a href="https://www.jetbrains.com/?from=p-ripple-core" target="__blank">
    <img src="https://www.jetbrains.com/company/brand/img/jetbrains_logo.png" width="200">
</a>

[Jetbrains](https://www.jetbrains.com/?from=p-ripple-core) provides free development tools for this project

### Contact information

`Email` jingnigg@gmail.com

`WeChat` jingnigg
