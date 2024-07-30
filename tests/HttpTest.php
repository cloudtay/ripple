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

use GuzzleHttp\Psr7\Response;
use P\Net;
use PHPUnit\Framework\TestCase;
use Throwable;

use function hash;
use function P\async;
use function P\await;
use function P\run;
use function P\stop;
use function uniqid;

class HttpTest extends TestCase
{
    /**
     * @return
     */
    public function test_httpServerGet(): void
    {
        $answer = hash('sha256', uniqid());

        async(function () use ($answer) {
            try {
                /**
                 * @var Response $response
                 */
                $response = await(Net::Http()->Guzzle()->getAsync('http://127.0.0.1:8008/', [
                    'query' => [
                        'hash' => $answer,
                    ]
                ]));
                $this->assertEquals($answer, $response->getBody()->getContents());
            } catch (Throwable $exception) {
                $this->fail($exception->getMessage());
            } finally {
                stop();
            }
        });

        run();
    }

    public function test_httpServerPost(): void
    {
        $answer = hash('sha256', uniqid());

        Net::Http()->Guzzle()->postAsync('http://127.0.0.1:8008/', [
            'body'    => $answer,
            'timeout' => 5,
        ])
            ->then(function (Response $response) use ($answer) {
                $this->assertEquals($answer, $response->getBody()->getContents());
            })
            ->except(function (Throwable $throwable) {
                $this->fail($throwable->getMessage());
            })
            ->finally(function () {
                stop();
            });

        run();
    }
}
