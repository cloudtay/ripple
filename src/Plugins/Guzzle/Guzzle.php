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

namespace Psc\Plugins\Guzzle;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psc\Core\Coroutine\Promise;
use Psc\Core\LibraryAbstract;

use function array_merge;
use function P\registerForkHandler;

/**
 *
 */
class Guzzle extends LibraryAbstract
{
    /**
     * @var LibraryAbstract
     */
    protected static LibraryAbstract $instance;

    /**
     * @var HandlerStack
     */
    private HandlerStack $handlerStack;

    /**
     *
     */
    public function __construct()
    {
        $this->install();
        $this->registerOnFork();
    }

    /**
     * @return void
     */
    private function registerOnFork(): void
    {
        registerForkHandler(function () {
            $this->install();
            $this->registerOnFork();
        });
    }

    /**
     * @param array|null $config
     * @return Client
     */
    public function client(array|null $config = array()): Client
    {
        $config = array_merge(['handler' => $this->handlerStack], $config);
        return new Client($config);
    }

    /**
     * @return void
     */
    private function install(): void
    {
        $curlMultiHandler   = new PHandler();
        $this->handlerStack = HandlerStack::create($curlMultiHandler);
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array  $options
     * @return Promise<Response>
     */
    public function requestAsync(string $method, string $uri, array $options = array()): Promise
    {
        return new Promise(function (Closure $r, Closure $d) use ($method, $uri, $options) {
            $this->client()->requestAsync($method, $uri, $options)->then($r, $d);
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise<Response>
     */
    public function getAsync(string $uri, array $options = array()): Promise
    {
        return new Promise(function (Closure $r, Closure $d) use ($uri, $options) {
            $this->translatePromise(
                $this->client()->getAsync($uri, $options),
                $r,
                $d
            );
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise<Response>
     */
    public function postAsync(string $uri, array $options = array()): Promise
    {
        return new Promise(function (Closure $r, Closure $d) use ($uri, $options) {
            $this->translatePromise(
                $this->client()->postAsync($uri, $options),
                $r,
                $d
            );
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise<Response>
     */
    public function putAsync(string $uri, array $options = array()): Promise
    {
        return new Promise(function (Closure $r, Closure $d) use ($uri, $options) {
            $this->translatePromise(
                $this->client()->putAsync($uri, $options),
                $r,
                $d
            );
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise<Response>
     */
    public function deleteAsync(string $uri, array $options = array()): Promise
    {
        return new Promise(function (Closure $r, Closure $d) use ($uri, $options) {
            $this->translatePromise(
                $this->client()->deleteAsync($uri, $options),
                $r,
                $d
            );
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise<Response>
     */
    public function headAsync(string $uri, array $options = array()): Promise
    {
        return new Promise(function (Closure $r, Closure $d) use ($uri, $options) {
            $this->translatePromise(
                $this->client()->headAsync($uri, $options),
                $r,
                $d
            );
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise<Response>
     */
    public function patchAsync(string $uri, array $options = array()): Promise
    {
        return new Promise(function (Closure $r, Closure $d) use ($uri, $options) {
            $this->translatePromise(
                $this->client()->patchAsync($uri, $options),
                $r,
                $d
            );
        });
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array  $options
     * @return Response
     * @throws GuzzleException
     */
    public function request(string $method, string $uri, array $options = array()): Response
    {
        return $this->client()->request($method, $uri, $options);
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Response
     * @throws GuzzleException
     */
    public function get(string $uri, array $options = array()): Response
    {
        return $this->client()->get($uri, $options);
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Response
     * @throws GuzzleException
     */
    public function post(string $uri, array $options = array()): Response
    {
        return $this->client()->post($uri, $options);
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Response
     * @throws GuzzleException
     */
    public function put(string $uri, array $options = array()): Response
    {
        return $this->client()->put($uri, $options);
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Response
     * @throws GuzzleException
     */
    public function delete(string $uri, array $options = array()): Response
    {
        return $this->client()->delete($uri, $options);
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Response
     * @throws GuzzleException
     */
    public function head(string $uri, array $options = array()): Response
    {
        return $this->client()->head($uri, $options);
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Response
     * @throws GuzzleException
     */
    public function patch(string $uri, array $options = array()): Response
    {
        return $this->client()->patch($uri, $options);
    }

    /**
     * @param PromiseInterface $guzzlePromise
     * @param Closure          $localR
     * @param Closure          $localD
     * @return void
     */
    private function translatePromise(PromiseInterface $guzzlePromise, Closure $localR, Closure $localD): void
    {
        $guzzlePromise->then(
            fn (mixed $result) => $this->onCallback($result, $localR),
            fn (mixed $reason) => $this->onCallback($reason, $localD)
        );
    }

    /**
     * @param mixed   $result
     * @param Closure $localCallback
     * @return void
     */
    private function onCallback(mixed $result, Closure $localCallback): void
    {
        $localCallback($result);
    }
}
