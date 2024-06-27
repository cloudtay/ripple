<?php declare(strict_types=1);
/*
 * Copyright (c) 2023 cclilshy
 * Contact Information:
 * Email: jingnigg@gmail.com
 * Website: https://cc.cloudtay.com/
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
 * 版权所有 (c) 2023 cclilshy
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

namespace Cclilshy\PRippleEvent\Facades;

use Cclilshy\PRippleEvent\Core\Coroutine\Promise;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use function A\promise;
use function A\repeat;
use function array_merge;

class Guzzle
{
    private static Guzzle    $instance;
    private CurlMultiHandler $curlMultiHandler;
    private HandlerStack     $handlerStack;

    private function __construct()
    {
        $this->curlMultiHandler = new CurlMultiHandler;
        $this->handlerStack     = HandlerStack::create($this->curlMultiHandler);
        repeat(0.1, function () {
            $this->curlMultiHandler->tick();
        });
    }

    /**
     * @param string $method
     * @param string $uri
     * @param array  $options
     * @return Promise
     */
    public static function requestAsync(string $method, string $uri, array $options = []): Promise
    {
        return promise(function ($r, $d) use ($method, $uri, $options) {
            Guzzle::client()->requestAsync($method, $uri, $options)->then($r, $d);
        });
    }

    /**
     * @param array|null $config
     * @return Client
     */
    public static function client(array|null $config = []): Client
    {
        $config = array_merge(['handler' => Guzzle::getInstance()->handlerStack], $config);
        return new Client($config);
    }

    public static function getInstance(): Guzzle
    {
        if (!isset(Guzzle::$instance)) {
            Guzzle::$instance = new Guzzle();
        }
        return Guzzle::$instance;
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise
     */
    public static function getAsync(string $uri, array $options = []): Promise
    {
        return promise(function ($r, $d) use ($uri, $options) {
            Guzzle::client()->getAsync($uri, $options)->then($r, $d);
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise
     */
    public static function postAsync(string $uri, array $options = []): Promise
    {
        return promise(function ($r, $d) use ($uri, $options) {
            Guzzle::client()->postAsync($uri, $options)->then($r, $d);
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise
     */
    public static function putAsync(string $uri, array $options = []): Promise
    {
        return promise(function ($r, $d) use ($uri, $options) {
            Guzzle::client()->putAsync($uri, $options)->then($r, $d);
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise
     */
    public static function deleteAsync(string $uri, array $options = []): Promise
    {
        return promise(function ($r, $d) use ($uri, $options) {
            Guzzle::client()->deleteAsync($uri, $options)->then($r, $d);
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise
     */
    public static function headAsync(string $uri, array $options = []): Promise
    {
        return promise(function ($r, $d) use ($uri, $options) {
            Guzzle::client()->headAsync($uri, $options)->then($r, $d);
        });
    }

    /**
     * @param string $uri
     * @param array  $options
     * @return Promise
     */
    public static function patchAsync(string $uri, array $options = []): Promise
    {
        return promise(function ($r, $d) use ($uri, $options) {
            Guzzle::client()->patchAsync($uri, $options)->then($r, $d);
        });
    }
}
