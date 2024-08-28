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
PRipple是一个现代化的、高性能的原生PHP协程引擎, 旨在解决PHP在高并发、复杂网络通信和数据操作方面的挑战。
该引擎采用创新的架构和高效的编程模型, 为现代 Web 和 Web 应用程序提供强大而灵活的后端支持。
通过使用 PRipple, 你将体验到从系统全局视图管理任务并高效处理网络流量和数据的优势。 </p>

## 设计哲学

> `Go`语言的协程模型是PRipple的设计灵感来源, 随着`PHP8`引入更轻量级的`Fiber`取代了`Generator`的协程模型,
> 我们的设计理念得以通过PHP自举的方式实现, 实现了一个高性能的协程引擎。同时我们使用了`revolt`作为PRipple的底层驱动库,
> 使得PRipple完美兼容原有的PHP生态

### 适用组件库

> 我们允许用户自行选择适用的组件库, 所有组件只需像文档中描述的方式即可无需额外配置

**🚀 [Guzzle](https://docs.guzzlephp.org/en/stable/)**  
PHP应用最为广泛的HTTP客户端

**🔥 [AmPHP](https://amphp.org/)**  
提供丰富的PHP异步组件供用户自行封装

**🟢 [PDrive](https://github.com/cloudtay/p-ripple-drive)**  
官方提供的高性能驱动库，无缝接入你的传统应用

**🟢 [PRipple](https://github.com/cloudtay/p-ripple-core)**  
提供标准的协程架构与工具用于迅速开发或封装传统应用

**More** 🌗

### 事件库指南

|  扩展类型   | 推荐使用 | 兼容性 |              说明               |
|:-------:|:----:|:---:|:-----------------------------:|
| `libev` |  🟢  | 🟢️ | `Ev`是更加高效的事件扩展,在各系统中表现一致,推荐使用 |
|  `原生`   |  ️   | 🟢  |       支持PHP内置select机制使用       |
| `event` |      | 🌗  |     在不同系统下的事件特性不统一,不推荐使用      |

## 安装

---

````bash
composer require cclilshy/p-ripple-core
````

### Ev扩展安装

```bash
pecl install ev
```

### 开始学习

你可以访问PRipple的[文档](https://p-ripple.cloudtay.com/)开始阅读

我们建议你从[手动安装](https://p-ripple.cloudtay.com/docs/install/professional)开始, 便于更好地理解PRipple的工作流程

如果你想快速部署并使用PRipple的服务, 你可以直接访问[快速部署](https://p-ripple.cloudtay.com/docs/install/server)

## 特别致谢

---

<a href="https://www.jetbrains.com/?from=p-ripple-core" target="__blank">
    <img src="https://www.jetbrains.com/company/brand/img/jetbrains_logo.png" width="200">
</a>

[Jetbrains](https://www.jetbrains.com/?from=p-ripple-core) 为本项目提供了免费的开发工具

### 联系方式

`电邮` jingnigg@gmail.com

`微信` jingnigg

| 群聊直通车                                                                                                               | 作者微信(邀请入群)                                                                                                          |
|---------------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------|
| <img src="https://cdn.learnku.com/uploads/images/202408/26/114411/Dwy8v4gzjL.jpg!large" width="200" height="200" /> | <img src="https://cdn.learnku.com/uploads/images/202408/26/114411/h2nOpetJb0.jpg!large" width="200" height="200" /> |

