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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use P\Net;
use P\Plugin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psc\Core\Http\Server\Request;
use Psc\Core\Http\Server\Response;
use Psc\Utils\Output;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

use function Co\async;
use function file_put_contents;
use function fopen;
use function md5;
use function md5_file;
use function P\cancelAll;
use function P\defer;
use function P\tick;
use function str_repeat;
use function stream_context_create;
use function sys_get_temp_dir;
use function tempnam;
use function uniqid;

class HttpTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    #[Test]
    public function test_httpServer(): void
    {
        defer(function () {
            try {
                $this->httpGet();
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }

            try {
                $this->httpPost();
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }

            try {
                $this->httpFile();
            } catch (Throwable $exception) {
                Output::exception($exception);
            }

            try {
                $this->httpClient();
            } catch (Throwable $exception) {
                Output::exception($exception);
            }

            cancelAll();
        });
        $context = stream_context_create([
            'socket' => [
                'so_reuseport' => 1,
                'so_reuseaddr' => 1,
            ],
        ]);
        $server = Net::Http()->server('http://127.0.0.1:8008', $context);
        $server->onRequest(function (Request $request, Response $response) {
            if($request->getRequestUri() === '/upload') {
                /**
                 * @var UploadedFile $file
                 */
                $file = $request->files->get('file')[0];
                $hash = $request->request->get('hash');
                $this->assertEquals($hash, md5_file($file->getRealPath()));
                $response->setContent(fopen($file->getRealPath(), 'r'))->respond();

                return;
            }

            if ($request->isMethod('get')) {
                $response->setContent($request->query->get('query'))->respond();
                return;
            }

            if ($request->isMethod('post')) {
                $response->setContent($request->request->get('query'))->respond();
                return;
            }
        });

        $server->listen();
        tick();
    }

    /**
     * @return void
     * @throws Throwable
     */
    private function httpGet(): void
    {
        $hash     = md5(uniqid());
        $client   = Plugin::Guzzle();
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
        $client   = Plugin::Guzzle();
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
        $client = Plugin::Guzzle();
        $path = tempnam(sys_get_temp_dir(), 'test');
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
                    'name' => 'hash',
                    'contents' => $hash
                ]
            ],
            'timeout'  => 10,
            'sink'     => $path . '.bak'
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
            'https://juejin.cn',
            'https://www.csdn.net',
            'https://www.cnblogs.com/',
            'https://business.oceanengine.com/login',
            'https://www.laruence.com/'
        ];

        $x = 0;
        $y = 0;

        foreach ($urls as $i => $url) {
            try {
                async(function () use ($i, $urls, &$x, &$y) {
                    try {
                        $response = \Co\Plugin::Guzzle()->get($urls[$i], ['timeout' => 5]);
                        if($response->getStatusCode() === 200) {
                            $x++;
                        }

                        // echo "Request ({$i}){$urls[$i]} response: {$response->getStatusCode()}\n";
                    } catch (Throwable $e) {
                        echo "\n";
                        echo "Request ({$i}){$urls[$i]} error: {$e->getMessage()}\n";
                        Output::exception($e);
                    }

                    try {
                        $guzzleResponse = (new Client())->get($urls[$i], ['timeout' => 5]);
                        if($guzzleResponse->getStatusCode() === 200) {
                            $y++;
                        }

                        // echo "GuzzleRequest ({$i}){$urls[$i]} response: {$guzzleResponse->getStatusCode()}\n";
                    } catch (Throwable $e) {
                        echo "\n";
                        echo "GuzzleRequest ({$i}){$urls[$i]} error: {$e->getMessage()}\n";
                    }
                })->await();
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }

        echo "\n";
        echo("Request success: {$x}, GuzzleRequest success: {$y}\n");
    }
}
