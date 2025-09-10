# Stream 异常处理指南

## 概述

Stream 模块使用清晰的异常层次结构，将内部控制流异常与用户代码应该处理的应用程序级异常分离开来。

## 异常层次结构

```text
RuntimeException
├── StreamException (用户可捕获异常的基础类)
│   └── TransportException (可恢复的传输错误)
│       ├── TransportTimeoutException (超时错误)
│       ├── ConnectionTimeoutException (连接超时)
│       └── WriteClosedException (写入已关闭的流)
└── ConnectionException (内部控制流 - 请勿捕获)
```

## 核心原则

### 1. 内部控制流异常（请勿捕获）

**ConnectionException** 是反应器专门用于连接终止的内部异常。它实现了 `AbortConnection` 标记接口。

- **目的**：向反应器发出立即连接终止的信号
- **用法**：仅在连接变得不可用时内部抛出
- **处理**：仅由反应器的异常边界捕获，用户代码永远不要捕获

```php
// ❌ 永远不要这样做
try {
    $stream->read(1024);
} catch (ConnectionException $e) {
    // 这会破坏反应器的控制流！
}

// ✅ 使用连接生命周期事件代替
$stream->onClose(function (CloseEvent $event) {
    echo "连接已关闭: {$event->reason->value}";
});
```

### 2. 应用程序级异常（安全捕获）

**TransportException** 及其子类表示应用程序代码可以处理的可恢复错误。

```php
// ✅ 安全捕获和处理
try {
    $stream->write($data);
} catch (WriteClosedException $e) {
    // 写入端已关闭，但连接可能仍然可读
    echo "无法写入: {$e->getMessage()}";
} catch (TransportException $e) {
    // 其他传输级错误
    echo "传输错误: {$e->getMessage()}";
}
```

## 连接生命周期事件

使用生命周期事件而不是捕获异常来处理连接状态变化：

### onClose(CloseEvent)

当连接终止时触发一次。

```php
$stream->onClose(function (CloseEvent $event) {
    echo "已关闭: {$event->reason->value} 由 {$event->initiator}";
    // 清理资源，更新连接池等
});
```

**CloseEvent** 提供：

- `reason`: ConnectionAbortReason 枚举 (PEER_CLOSED, RESET, TIMEOUT 等)
- `initiator`: 'peer' | 'local' | 'system'
- `message`: 可选的描述性消息
- `lastError`: 可选的底层异常
- `timestamp`: 关闭发生的时间

### onReadableEnd()

当读取端关闭（EOF）但连接可能仍然可写时触发。

```php
$stream->onReadableEnd(function () use ($stream) {
    echo "读取端已关闭 - 对等方不再有数据";
    // 仍可以写入最终响应，然后关闭
    $stream->write("HTTP/1.1 200 OK\r\n\r\nGoodbye");
    $stream->close();
});
```

### onWritableEnd()

当写入端关闭但连接可能仍然可读时触发。

```php
$stream->onWritableEnd(function () use ($stream) {
    echo "写入端已关闭 - 无法发送更多数据";
    // 仍可以从对等方读取剩余数据
});
```

## 半关闭支持

半关闭允许连接的一侧关闭而另一侧保持打开。这对于像 HTTP 这样的协议很有用，客户端发送完整请求，然后服务器发送完整响应。

### 配置

```php
$stream = new Stream($resource);
$stream->supportsHalfClose = true; // 默认: true
```

### 行为

当 `supportsHalfClose = true` 时：

- `read()` 返回 EOF 触发 `onReadableEnd()`（如果已注册），否则抛出 `ConnectionException`
- `write()` 收到 EPIPE 触发 `onWritableEnd()`（如果已注册），否则抛出 `ConnectionException`

当 `supportsHalfClose = false` 时：

- EOF 或 EPIPE 立即抛出 `ConnectionException` 用于反应器终止

## 错误分类

### 致命错误（→ ConnectionException）

这些错误表示连接不再可用：

- 对等方关闭连接（无半关闭支持的 EOF）
- 对等方重置连接（ECONNRESET）
- TLS 致命警报
- 管道破裂（无半关闭支持的 EPIPE）

### 可恢复错误（→ TransportException）

这些错误可以由应用程序逻辑处理：

- 连接超时（可以重试）
- 写入已关闭的流（可以检测和处理）
- 协议级错误（应用程序可以决定响应）
- 临时资源不可用

## 从旧 API 迁移

### 之前

```php
try {
    $data = $stream->read(1024);
} catch (ConnectionException $e) {
    if ($e->getCode() === ConnectionException::CONNECTION_CLOSED) {
        // 处理关闭
    }
}
```

### 之后

```php
// 使用事件进行生命周期管理
$stream->onClose(function (CloseEvent $event) {
    if ($event->reason === ConnectionAbortReason::PEER_CLOSED) {
        // 处理关闭
    }
});

$stream->onReadableEnd(function () {
    // 处理 EOF/半关闭
});

// 只捕获可恢复异常
try {
    $data = $stream->read(1024);
} catch (TransportException $e) {
    // 只处理可恢复错误
}
```

## 最佳实践

1. **永远不要捕获 ConnectionException** - 使用生命周期事件代替
2. **注册 onClose 进行清理** - 保证每个连接调用一次
3. **使用 onReadableEnd/onWritableEnd** 处理半关闭协议
4. **捕获 TransportException** 进行可恢复错误处理
5. **让反应器处理致命错误** - 它会清理并发出事件

## 常见模式

### HTTP 服务器

```php
$stream->onReadableEnd(function () use ($stream, $response) {
    // 客户端完成发送请求，发送响应
    $stream->write($response);
    $stream->close();
});

$stream->onClose(function (CloseEvent $event) use ($connectionPool) {
    $connectionPool->remove($stream);
});
```

### 数据库客户端

```php
$stream->onClose(function (CloseEvent $event) use ($pendingQueries) {
    // 失败所有待处理查询
    foreach ($pendingQueries as $query) {
        $query->fail(new TransportException("连接丢失: {$event->reason->value}"));
    }
});
```

### WebSocket

```php
$stream->onClose(function (CloseEvent $event) use ($subscriptions) {
    // 清理订阅
    foreach ($subscriptions as $sub) {
        $sub->cancel();
    }
});
```
