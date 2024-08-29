<?php declare(strict_types=1);

namespace Psc\Core\Http\Client;

use Co\IO;
use Psc\Core\Socket\Proxy\ProxyHttp;
use Psc\Core\Socket\Proxy\ProxySocks5;
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Exception\ConnectionException;
use Throwable;

use function array_pop;
use function Co\cancel;
use function Co\cancelForkHandler;
use function Co\registerForkHandler;
use function parse_url;

class ConnectionPool
{
    /*** @var array */
    private array $idleConnections = [];

    /*** @var array */
    private array $listenEventMap = [];

    /*** @var int */
    private int $forkEventId;

    public function __construct()
    {
        $this->registerForkHandler();
    }

    public function __destruct()
    {
        $this->clearConnectionPool();
        cancelForkHandler($this->forkEventId);
    }

    /**
     * @param string      $host
     * @param int         $port
     * @param bool        $ssl
     * @param int|float   $timeout
     * @param string|null $proxy http://username:password@proxy.example.com:8080
     * @return Connection
     * @throws ConnectionException
     * @throws Throwable
     */
    public function pullConnection(
        string $host,
        int $port,
        bool $ssl = false,
        int|float $timeout = 0,
        string|null $proxy = null,
    ): Connection {
        $key = ConnectionPool::generateConnectionKey($host, $port, $ssl);
        if (!isset($this->idleConnections[$key]) || empty($this->idleConnections[$key])) {
            // 连接创建逻辑
            return $this->createConnection($host, $port, $ssl, $timeout, $proxy);
        } else {
            /**
             * @var Connection $connection
             */
            $connection = array_pop($this->idleConnections[$key]);
            if (empty($this->idleConnections[$key])) {
                unset($this->idleConnections[$key]);
            }

            cancel($this->listenEventMap[$connection->stream->id]);
            unset($this->listenEventMap[$connection->stream->id]);
            return $connection;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @param string      $host
     * @param int         $port
     * @param bool        $ssl
     * @param int|float   $timeout
     * @param string|null $proxy
     * @return Connection
     * @throws ConnectionException
     * @throws Throwable
     */
    private function createConnection(string $host, int $port, bool $ssl, int|float $timeout, string|null $proxy): Connection
    {
        if ($proxy) {
            $parse = parse_url($proxy);
            if (!isset($parse['host'], $parse['port'])) {
                throw new ConnectionException('Invalid proxy address');
            }
            $payload = ['host' => $host, 'port' => $port];
            if (isset($parse['user'], $parse['pass'])) {
                $payload['username'] = $parse['user'];
                $payload['password'] = $parse['pass'];
            }
            $proxySocketStream = $this->createProxySocketStream($parse, $payload);
            $ssl && IO::Socket()->streamEnableCrypto($proxySocketStream)->await();
            return new Connection($proxySocketStream);
        }

        $stream = IO::Socket()->streamSocketClient("tcp://{$host}:{$port}", $timeout)->await();
        $ssl && IO::Socket()->streamEnableCrypto($stream)->await();
        return new Connection($stream);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @param array $parse
     * @param array $payload
     * @return SocketStream
     * @throws ConnectionException
     * @throws Throwable
     */
    private function createProxySocketStream(array $parse, array $payload): SocketStream
    {
        switch ($parse['scheme']) {
            case 'socks':
            case 'socks5':
                return ProxySocks5::connect("tcp://{$parse['host']}:{$parse['port']}", $payload)->getSocketStream();
            case 'http':
            case 'https':
                $secure = $parse['scheme'] === 'https';
                return ProxyHttp::connect("tcp://{$parse['host']}:{$parse['port']}", $payload, $secure)->getSocketStream();
            default:
                throw new ConnectionException('Unsupported proxy protocol');
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @return void
     */
    private function registerForkHandler(): void
    {
        $this->forkEventId = registerForkHandler(function () {
            $this->registerForkHandler();
            $this->clearConnectionPool();
        });
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @return void
     */
    private function clearConnectionPool(): void
    {
        foreach ($this->idleConnections as $keyI => $connections) {
            foreach ($connections as $keyK => $connection) {
                $connection->stream->close();
                unset($this->idleConnections[$keyI][$keyK]);
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @param Connection $connection
     * @param string     $key
     * @return void
     */
    public function pushConnection(Connection $connection, string $key): void
    {
        $streamId = $connection->stream->id;
        if (isset($this->listenEventMap[$streamId])) {
            cancel($this->listenEventMap[$streamId]);
            unset($this->listenEventMap[$streamId]);
        }
        $this->idleConnections[$key][$streamId] = $connection;
        $this->listenEventMap[$streamId]        = $connection->stream->onReadable(function (SocketStream $stream) use ($key, $connection) {
            try {
                if ($stream->read(1) === '' && $stream->eof()) {
                    throw new ConnectionException('Connection closed by peer');
                }
            } catch (Throwable) {
                $this->removeConnection($key, $connection);
                $stream->close();
            }
        });
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 23:18
     * @param string     $key
     * @param Connection $connection
     * @return void
     */
    private function removeConnection(string $key, Connection $connection): void
    {
        $streamId = $connection->stream->id;
        unset($this->idleConnections[$key][$streamId]);
        if (empty($this->idleConnections[$key])) {
            unset($this->idleConnections[$key]);
        }
        if (isset($this->listenEventMap[$streamId])) {
            cancel($this->listenEventMap[$streamId]);
            unset($this->listenEventMap[$streamId]);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 09:43
     * @param string $host
     * @param int    $port
     * @param bool   $ssl
     * @return string
     */
    public static function generateConnectionKey(string $host, int $port, bool $ssl): string
    {
        return ($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    }
}
