# Stream 异常处理指南

## 异常层次结构

```
Exception (基础异常)
├── StreamInternalException (框架内部异常) ⚠️ 禁止应用层捕获
└── ConnectionException (应用层连接异常基类)
    ├── ConnectionTimeoutException (连接超时)
    ├── ConnectionCloseException (连接关闭)
    └── ConnectionHandshakeException (握手失败)
```

## 异常职能划分

### StreamInternalException (框架层)
**用途**: 框架内部控制流，用于底层 I/O 失败时的异常穿透

**抛出场景**:
- `fread()` 返回 `false`
- `fwrite()` 返回 `false`  
- `socket_recv()` 返回 `false`
- Socket 连接建立失败

**处理方式**: 
- ❌ **禁止应用层捕获**
- ✅ 应该穿透到框架的兜底处理区域
- ✅ 自动关闭连接并清理资源

### ConnectionException (应用层)
**用途**: 应用层可以捕获和处理的连接相关异常

**子类异常**:
- `ConnectionTimeoutException`: 连接超时，用户可重试
- `ConnectionCloseException`: 连接被关闭，用户可重连
- `ConnectionHandshakeException`: 握手失败，用户可降级或重试

**处理方式**:
- ✅ 应用层可以捕获
- ✅ 用户可以实施重试、降级等策略
- ✅ 用于业务逻辑处理

## 使用示例

### ❌ 错误用法
```php
try {
    $stream->onReadable(function() use ($stream) {
        $data = $stream->read(1024); // 可能抛出 StreamInternalException
    });
} catch (StreamInternalException $e) {
    // 错误！不应该捕获内部异常
    // 这会阻止异常穿透到框架处理区域
}
```

### ✅ 正确用法
```php
try {
    $socket = Socket::connect('tcp://example.com:443');
    $socket->enableSSL(); // 可能抛出 ConnectionHandshakeException
} catch (ConnectionHandshakeException $e) {
    // 正确！可以捕获并处理握手失败
    error_log("SSL handshake failed: " . $e->getMessage());
    // 可以尝试降级到 HTTP 或重试
}

try {
    $socket = Socket::connect('tcp://example.com:80', 5.0);
} catch (ConnectionTimeoutException $e) {
    // 正确！可以捕获并处理超时
    error_log("Connection timeout: " . $e->getMessage());
    // 可以重试或使用备用服务器
}
```

## 设计原则

1. **框架异常穿透**: `StreamInternalException` 必须穿透到框架处理区域
2. **应用异常可控**: `ConnectionException` 及子类供应用层处理
3. **职责分离**: 不同层次的异常承担不同职责
4. **类型安全**: 通过类型系统防止误用

## 迁移指南

如果您的代码中捕获了原来的 `ConnectionException`，请检查：

1. 如果捕获的是底层 I/O 异常，请移除 catch 块
2. 如果捕获的是业务异常，请使用具体的子类异常
3. 如果需要捕获所有应用层连接异常，继续使用 `ConnectionException`