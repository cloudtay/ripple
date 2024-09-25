<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Tests;

use Co\Net;
use Co\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psc\Core\Http\Server\Request;
use Psc\Utils\Output;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function Co\async;
use function Co\cancelAll;
use function file_put_contents;
use function fopen;
use function gc_collect_cycles;
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
            } catch (Throwable $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpPost();
            } catch (Throwable $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpFile();
            } catch (Throwable $exception) {
                Output::exception($exception);
                throw $exception;
            }
        }

        gc_collect_cycles();
        $baseMemory = memory_get_usage();

        for ($i = 0; $i < 10; $i++) {
            try {
                $this->httpGet();
            } catch (Throwable $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpPost();
            } catch (Throwable $exception) {
                Output::exception($exception);
                throw $exception;
            }

            try {
                $this->httpFile();
            } catch (Throwable $exception) {
                Output::exception($exception);
                throw $exception;
            }
        }

        Plugin::Guzzle()->getHttpClient()->getConnectionPool()->clearConnectionPool();
        gc_collect_cycles();

        if ($baseMemory !== memory_get_usage()) {
            echo "\nThere may be a memory leak.\n";
        }

        /**
         * HttpClient测试
         */
        try {
            $this->httpClient();
        } catch (Throwable $exception) {
            echo($exception->getMessage() . PHP_EOL);
        }

        cancelAll();
    }

    /**
     * @return void
     * @throws Throwable
     */
    private function httpGet(): void
    {
        $hash     = md5(uniqid());
        $client   = Plugin::Guzzle()->newClient();
        $response = $client->get('http://127.0.0.1:8008/', [
            'query'   => [
                'query' => $hash,
            ],
            'timeout' => 1
        ]);

        $result = $response->getBody()->getContents();
        $this->assertEquals($hash, $result);
    }

    /**
     * @return void
     * @throws Throwable
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
     * @throws Throwable
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

        $x = 0;
        $y = 0;

        $list = [];
        foreach ($urls as $i => $url) {
            $list[] = async(function () use ($i, $url, $urls, &$x, &$y) {
                try {
                    $response = Plugin::Guzzle()->newClient()->get($url, ['timeout' => 10]);
                    if ($response->getStatusCode() === 200) {
                        $x++;
                    }

                    echo "Request ({$i}){$url} response: {$response->getStatusCode()}\n";
                } catch (Throwable $exception) {
                    echo "\n";
                    echo "Request ({$i}){$url} error: {$exception->getMessage()}\n";
                    echo($exception->getMessage() . PHP_EOL);
                }

                try {
                    $guzzleResponse = (new Client())->get($url, ['timeout' => 10]);
                    if ($guzzleResponse->getStatusCode() === 200) {
                        $y++;
                    }

                    echo "GuzzleRequest ({$i}){$url} response: {$guzzleResponse->getStatusCode()}\n";
                } catch (Throwable $exception) {
                    echo "\n";
                    echo "GuzzleRequest ({$i}){$url} error: {$exception->getMessage()}\n";
                }
            });
        }

        foreach ($list as $promise) {
            $promise->await();
        }

        echo "\n";
        echo("Request success: {$x}, GuzzleRequest success: {$y}\n");
    }
}
