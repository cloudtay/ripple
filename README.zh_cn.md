<p align="center">
<img src="https://raw.githubusercontent.com/cloudtay/ripple/refs/heads/main/assets/images/logo.png" width="420" alt="Logo">
</p>
<p align="center">
<a href="#"><img src="https://img.shields.io/badge/PHP-%3E%3D%208.1-blue" alt="Build Status"></a>
<a href="https://packagist.org/packages/cloudtay/ripple"><img src="https://img.shields.io/packagist/dt/cloudtay/ripple" alt="Download statistics"></a>
<a href="https://packagist.org/packages/cloudtay/ripple"><img src="https://img.shields.io/packagist/v/cloudtay/ripple" alt="Stable version"></a>
<a href="https://packagist.org/packages/cloudtay/ripple"><img src="https://img.shields.io/packagist/l/cloudtay/ripple" alt="License"></a>
</p>
<p>
ripple是一个现代化的、高性能的原生PHP协程引擎, 旨在解决PHP在高并发、复杂网络通信和数据操作方面的挑战。
该引擎采用创新的架构和高效的编程模型, 为现代 Web 和 Web 应用程序提供强大而灵活的后端支持。
通过使用 ripple, 你将体验到从系统全局视图管理任务并高效处理网络流量和数据的优势。 </p>

### 🌟 群聊已开放加入~ 🌟

`🔥 交流群的大门已为各位先行者打开,加入ripple的交流群,一起探讨PHP协程的未来`

**`🎉 加入方式`** 通过以下方式添加作者微信即可加入交流群

| 微信二维码                                                                                                                |
|----------------------------------------------------------------------------------------------------------------------|
| <img src="https://raw.githubusercontent.com/cloudtay/ripple/refs/heads/main/assets/images/wechat.jpg" width="380" /> |

## 安装

````bash
composer require cloudtay/ripple
````

## 最新文档

你可以访问`ripple`的[文档](https://ripple.cloudtay.com/)开始阅读

我们建议你从[手动安装](https://ripple.cloudtay.com/docs/install/professional)开始, 便于更好地理解ripple的工作流程

如果你想快速部署并使用`ripple`的服务, 你可以直接访问[快速部署](https://ripple.cloudtay.com/docs/install/server)

## 附录

### 适用组件库

> 我们允许用户自行选择适用的组件库, 所有组件只需像文档中描述的方式即可无需额外配置

**🚀 [Guzzle](https://docs.guzzlephp.org/en/stable/)**  
PHP应用最为广泛的HTTP客户端

**🔥 [AmPHP](https://amphp.org/)**  
提供丰富的PHP异步组件供用户自行封装

**🚀 [Driver](https://github.com/cloudtay/ripple-driver)**  
官方提供的高性能驱动库，无缝接入你的传统应用

**🚀 [Webman-coroutine](https://github.com/workbunny/webman-coroutine)**
workbunny团队体统的webman协程扩展, 为Webman提供了协程支持

**🟢 [ripple](https://github.com/cloudtay/ripple)**  
提供标准的协程架构与工具用于迅速开发或封装传统应用

### 事件库指南

|  扩展类型   | 推荐使用 | 兼容性 |              说明               |
|:-------:|:----:|:---:|:-----------------------------:|
| `libev` | 🏅️  | 🟢️ | `Ev`是更加高效的事件扩展,在各系统中表现一致,推荐使用 |
|  `原生`   |  ️   | 🟢  |       支持PHP内置select机制使用       |
| `event` |      | 🌗  |     在不同系统下的事件特性不统一,不推荐使用      |

## 特别致谢

<a href="https://www.jetbrains.com/?from=ripple" target="__blank">
    <img src="https://www.jetbrains.com/company/brand/img/jetbrains_logo.png" width="200" alt="jetbrains">
</a>

[Jetbrains](https://www.jetbrains.com/?from=ripple) 为本项目提供了免费的开发工具

### 联系方式

`电邮` jingnigg@gmail.com

`微信` jingnigg

---
