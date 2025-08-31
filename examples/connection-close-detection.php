<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionStateException;
use Ripple\Stream\Exception\StreamInternalException;
use Ripple\Utils\Output;

use function Co\go;
use function Co\wait;
use function Co\sleep;

/**
 * 连接关闭检测示例
 * 
 * 展示不同场景下如何检测和处理连接关闭：
 * 1. 被动检测：onClose 回调
 * 2. 主动检测：isAlive() 和 assertAlive()
 * 3. 异常检测：在 I/O 操作中捕获异常
 * 4. 半关闭检测：isHalfClosed()
 */

// 场景1：被动检测 - 使用 onClose 回调
go(function () {
    Output::info("=== 场景1: 被动检测 (onClose) ===");
    
    $server = Socket::server('tcp://127.0.0.1:9001');
    $server->setBlocking(false);
    
    $server->onReadable(function () use ($server) {
        $client = $server->accept();
        $client->setBlocking(false);
        
        // 设置关闭回调
        $client->onClose(function () {
            Output::success("✓ onClose: 客户端连接已关闭");
        });
        
        $client->onReadable(function () use ($client) {
            try {
                $data = $client->read(1024);
                if ($data === '') {
                    Output::info("收到空数据，客户端可能已关闭");
                    $client->close(); // 这会触发 onClose
                    return;
                }
                Output::info("收到数据: " . trim($data));
            } catch (StreamInternalException $e) {
                // 底层异常，让其穿透
                throw $e;
            }
        });
    });
    
    sleep(0.1);
    
    // 模拟客户端连接并关闭
    $client = Socket::connect('tcp://127.0.0.1:9001');
    $client->write("Hello Server");
    sleep(0.1);
    $client->close(); // 这会触发服务端的 onClose
    
    sleep(0.5);
    $server->close();
});

// 场景2：主动检测 - 在业务逻辑中检查连接状态
go(function () {
    Output::info("=== 场景2: 主动检测 (isAlive/assertAlive) ===");
    
    $server = Socket::server('tcp://127.0.0.1:9002');
    $server->setBlocking(false);
    
    $server->onReadable(function () use ($server) {
        $client = $server->accept();
        $client->setBlocking(false);
        
        // 模拟批量操作，需要在过程中检测连接状态
        go(function () use ($client) {
            for ($i = 0; $i < 10; $i++) {
                sleep(0.1);
                
                // 主动检测连接状态
                if (!$client->isAlive()) {
                    Output::warning("✗ 检测到连接已关闭，停止批量操作");
                    break;
                }
                
                try {
                    // 断言连接活跃
                    $client->assertAlive();
                    Output::info("✓ 批量操作 $i: 连接正常");
                } catch (ConnectionStateException $e) {
                    Output::warning("✗ 连接状态异常: {$e->getMessage()} (原因: {$e->reason})");
                    
                    if ($e->shouldReconnect()) {
                        Output::info("需要重连");
                    } else {
                        Output::info("优雅关闭，无需重连");
                    }
                    break;
                }
            }
        });
        
        // 3秒后关闭客户端，模拟连接中断
        sleep(3);
        $client->close();
    });
    
    sleep(0.1);
    
    $client = Socket::connect('tcp://127.0.0.1:9002');
    $client->write("Start batch operation");
    
    sleep(5);
    $server->close();
});

// 场景3：半关闭检测
go(function () {
    Output::info("=== 场景3: 半关闭检测 ===");
    
    $server = Socket::server('tcp://127.0.0.1:9003');
    $server->setBlocking(false);
    
    $server->onReadable(function () use ($server) {
        $client = $server->accept();
        $client->setBlocking(false);
        
        $client->onReadable(function () use ($client) {
            try {
                $data = $client->read(1024);
                
                if ($data === '') {
                    // 检查是否为半关闭状态
                    if ($client->isHalfClosed()) {
                        Output::info("✓ 检测到半关闭状态：对端关闭写端，但读端仍开放");
                        // 发送确认消息后关闭
                        $client->write("ACK: 收到所有数据\n");
                        $client->close();
                    } else {
                        Output::info("连接完全关闭");
                        $client->close();
                    }
                    return;
                }
                
                Output::info("收到数据: " . trim($data));
                
            } catch (StreamInternalException $e) {
                // 底层异常穿透
                throw $e;
            }
        });
    });
    
    sleep(0.1);
    
    $client = Socket::connect('tcp://127.0.0.1:9003');
    $client->write("Test half-close");
    sleep(0.1);
    
    // 模拟半关闭：关闭写端但保持读端
    stream_socket_shutdown($client->getStream(), STREAM_SHUT_WR);
    
    sleep(1);
    $client->close();
    $server->close();
});

// 场景4：连接池健康检查
go(function () {
    Output::info("=== 场景4: 连接池健康检查 ===");
    
    class SimpleConnectionPool {
        private array $connections = [];
        
        public function addConnection(Socket $conn): void {
            $this->connections[] = $conn;
        }
        
        public function getHealthyConnection(): ?Socket {
            foreach ($this->connections as $index => $conn) {
                if ($conn->isAlive()) {
                    Output::success("✓ 连接 $index 健康");
                    return $conn;
                }
                
                Output::warning("✗ 连接 $index 已死亡，从池中移除");
                unset($this->connections[$index]);
            }
            
            Output::warning("连接池中无可用连接");
            return null;
        }
        
        public function getConnectionCount(): int {
            return count($this->connections);
        }
    }
    
    $pool = new SimpleConnectionPool();
    
    // 创建一些连接
    for ($i = 0; $i < 3; $i++) {
        try {
            $conn = Socket::connect('tcp://127.0.0.1:80'); // 假设这个地址不存在
            $pool->addConnection($conn);
        } catch (StreamInternalException $e) {
            Output::warning("连接 $i 创建失败");
        }
    }
    
    Output::info("连接池初始大小: " . $pool->getConnectionCount());
    
    // 健康检查
    $healthyConn = $pool->getHealthyConnection();
    if ($healthyConn) {
        Output::success("找到健康连接");
    } else {
        Output::info("无健康连接可用");
    }
});

wait();