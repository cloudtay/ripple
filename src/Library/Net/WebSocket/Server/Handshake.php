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

namespace Psc\Library\Net\WebSocket\Server;

use Psc\Std\Stream\Exception\ConnectionException;

use function array_shift;
use function base64_encode;
use function count;
use function explode;
use function sha1;
use function strpos;
use function substr;
use function trim;

/**
 * Websocket握手处理器
 */
class Handshake
{
    /**
     * Attempts to recognize handshake data when receiving a client for the first time
     * @param Connection $client
     * @return bool
     */
    public const array NEED_HEAD = [
        'Host'                  => true,
        'Upgrade'               => true,
        'Connection'            => true,
        'Sec-WebSocket-Key'     => true,
        'Sec-WebSocket-Version' => true
    ];

    /**
     * @param Connection $client
     * @return bool|null
     * @throws ConnectionException
     */
    public static function accept(Connection $client): bool|null
    {
        $identityInfo = Handshake::verify($client->buffer);
        if ($identityInfo === null) {
            return null;
        } elseif ($identityInfo === false) {
            return false;
        } else {
            $secWebSocketAccept = Handshake::getSecWebSocketAccept($identityInfo['Sec-WebSocket-Key']);
            $client->stream->write(Handshake::generateResultContext($secWebSocketAccept));
            $client->buffer = '';
            return true;
        }
    }

    /**
     * 验证信息
     * @param string $buffer
     * @return array|false|null
     */
    public static function verify(string &$buffer): array|false|null
    {
        if ($index = strpos($buffer, "\r\n\r\n")) {
            $verify = Handshake::NEED_HEAD;
            $lines  = explode("\r\n", $buffer);
            $header = array();

            if (count($firstLineInfo = explode(" ", array_shift($lines))) !== 3) {
                return false;
            } else {
                $header['method']  = $firstLineInfo[0];
                $header['url']     = $firstLineInfo[1];
                $header['version'] = $firstLineInfo[2];
            }

            foreach ($lines as $line) {
                if ($_ = explode(":", $line)) {
                    $header[trim($_[0])] = trim($_[1] ?? '');
                    unset($verify[trim($_[0])]);
                }
            }

            if (count($verify) > 0) {
                return false;
            } else {
                $buffer = substr($buffer, $index + 4);
                return $header;
            }
        } else {
            return null;
        }
    }

    /**
     * @param string $key
     * @return string
     */
    private static function getSecWebSocketAccept(string $key): string
    {
        return base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /**
     * @param string $accept
     * @return string
     */
    private static function generateResultContext(string $accept): string
    {
        $headers = [
            'Upgrade'              => 'websocket',
            'Connection'           => 'Upgrade',
            'Sec-WebSocket-Accept' => $accept
        ];
        $context = "HTTP/1.1 101 NFS\r\n";
        foreach ($headers as $key => $value) {
            $context .= "{$key}: {$value} \r\n";
        }
        $context .= "\r\n";
        return $context;
    }
}
