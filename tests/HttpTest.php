<?php declare(strict_types=1);

namespace Tests;

use Co\Net;
use Co\Plugin;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Http\Server\Request;
use Psc\Utils\Output;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function Co\async;
use function Co\cancelAll;
use function file_put_contents;
use function fopen;
use function gc_collect_cycles;
use function is_array;
use function md5;
use function md5_file;
use function memory_get_usage;
use function str_repeat;
use function stream_context_create;
use function strtoupper;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function uniqid;

use const PHP_EOL;

class HttpTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    #[Test]
    public function test_httpServer(): void
    {
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
            ],
        ]);

        $server = Net::Http()->server('http://127.0.0.1:8008', $context);
        $server->onRequest(function (Request $request) {
            $url    = trim($request->SERVER['REQUEST_URI']);
            $method = strtoupper($request->SERVER['REQUEST_METHOD']);

            if ($url === '/upload') {
                /*** @var UploadedFile $file */
                $file = $request->FILES['file'][0];
                $hash = $request->POST['hash'] ?? '';
                $this->assertEquals($hash, md5_file($file->getRealPath()));
                $request->respond(fopen($file->getRealPath(), 'r'));
                return;
            }

            if ($method === 'GET') {
                $query = $request->GET['query'] ?? '';
                $request->respond($query);
                return;
            }

            if ($method === 'POST') {
                $query = $request->POST['query'] ?? '';
                $request->respond($query);
            }
        });

        $server->listen();

        for ($i = 0; $i < 10; $i++) {
            try {
                $this->httpGet();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpPost();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpFile();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }
        }

        gc_collect_cycles();
        $baseMemory = memory_get_usage();

        for ($i = 0; $i < 10; $i++) {
            try {
                $this->httpGet();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpPost();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpFile();
            } catch (GuzzleException $exception) {
                Output::exception($exception);
                throw $exception;
            }
        }

        Plugin::Guzzle()->getHttpClient()->getConnectionPool()->clearConnectionPool();
        gc_collect_cycles();

        if ($baseMemory !== memory_get_usage()) {
            echo "\nThere may be a memory leak.\n";
        }

        $this->httpClient();
        cancelAll();
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    private function httpGet(): void
    {
        $hash     = md5(uniqid());
        $client   = Plugin::Guzzle()->newClient();
        $response = $client->get('http://127.0.0.1:8008/', [
            'query' => [
                'query' => $hash,
            ]
        ]);

        $result = $response->getBody()->getContents();
        $this->assertEquals($hash, $result);
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    private function httpPost(): void
    {
        $hash     = md5(uniqid());
        $client   = Plugin::Guzzle()->newClient();
        $response = $client->post('http://127.0.0.1:8008/', [
            'json'    => [
                'query' => $hash,
            ],
            'timeout' => 1
        ]);

        $this->assertEquals($hash, $response->getBody()->getContents());
    }

    /**
     * @return void
     * @throws GuzzleException
     */
    private function httpFile(): void
    {
        $client = Plugin::Guzzle()->newClient();
        $path   = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($path, str_repeat('a', 81920));
        $hash = md5_file($path);
        $client->post('http://127.0.0.1:8008/upload', [
            'multipart' => [
                [
                    'name'     => 'file',
                    'contents' => fopen($path, 'r'),
                    'filename' => 'test.txt',
                ],
                [
                    'name'     => 'hash',
                    'contents' => $hash
                ]
            ],
            'timeout'   => 10,
            'sink'      => $path . '.bak'
        ]);
        $this->assertEquals($hash, md5_file($path . '.bak'));
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 10:01
     * @return void
     */
    private function httpClient(): void
    {
        $urls = [
            'https://www.baidu.com/',
            'https://www.qq.com/',
            'https://www.zhihu.com/',
            'https://www.taobao.com/',
            'https://www.jd.com/',
            'https://www.163.com/',
            'https://www.sina.com.cn/',
            'https://www.sohu.com/',
            'https://www.ifeng.com/',
            'https://juejin.cn/',
            'https://www.csdn.net/',
            'https://www.cnblogs.com/',
            'https://business.oceanengine.com/login',
            'https://www.laruence.com/',
            'https://www.php.net/',
        ];


        $list = [];
        foreach ($urls as $index => $url) {
            $list[] = async(function () use ($url, $urls, $index) {
                return [$index, Plugin::Guzzle()->newClient()->get($url, ['timeout' => 10])];
            });
        }

        foreach (Promise::futures($list) as $result) {
            if ($result instanceof Throwable) {
                echo $result->getMessage(), PHP_EOL;
            } elseif (is_array($result)) {
                [$index, $response] = $result;
                echo $index, ' ', $urls[$index], ' ';
                echo $response->getStatusCode(), ' ', $response->getReasonPhrase(), PHP_EOL;
            } else {
                echo 'Unknown error', PHP_EOL;
            }
        }
    }
}
