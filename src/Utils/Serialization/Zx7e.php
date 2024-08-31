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

namespace Psc\Utils\Serialization;

use function chr;
use function ord;
use function pack;
use function strlen;
use function strpos;
use function substr;
use function unpack;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:37
 */
class Zx7e
{
    private const FRAME_HEADER = 0x7E;


    private const FRAME_FOOTER = 0x7E;

    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * @param string $data
     *
     * @return string
     */
    public function encodeFrame(string $data): string
    {
        $length   = strlen($data);
        $checksum = $this->calculateChecksum($data);

        $frame = chr(Zx7e::FRAME_HEADER);
        $frame .= pack('n', $length);
        $frame .= $data;
        $frame .= chr($checksum);
        $frame .= chr(Zx7e::FRAME_FOOTER);

        return $frame;
    }

    /**
     * @param string $data
     *
     * @return int
     */
    public function calculateChecksum(string $data): int
    {
        $checksum = 0;
        for ($i = 0; $i < strlen($data); $i++) {
            $checksum ^= ord($data[$i]);
        }
        return $checksum;
    }

    /**
     * @param string $data
     *
     * @return array
     */
    public function decodeStream(string $data): array
    {
        $this->buffer .= $data;
        $frames       = array();

        while (($frame = $this->extractFrame()) !== null) {
            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * @return string|null
     */
    private function extractFrame(): string|null
    {
        $startPos = strpos($this->buffer, chr(Zx7e::FRAME_HEADER));

        if ($startPos === false) {
            $this->buffer = '';
            return null;
        }

        if ($startPos > 0) {
            $this->buffer = substr($this->buffer, $startPos);
        }

        if (strlen($this->buffer) < 4) {
            return null;
        }

        $length = unpack('n', substr($this->buffer, 1, 2))[1];

        if (strlen($this->buffer) < $length + 4) {
            return null;
        }

        $data     = substr($this->buffer, 3, $length);
        $checksum = ord($this->buffer[3 + $length]);
        $endPos   = 4 + $length;

        if ($endPos >= strlen($this->buffer) || ord($this->buffer[$endPos]) !== Zx7e::FRAME_FOOTER) {
            $this->buffer = substr($this->buffer, $endPos);
            return null;
        }

        $this->buffer = substr($this->buffer, $endPos + 1);

        if ($checksum !== $this->calculateChecksum($data)) {
            return null;
        }

        return $data;
    }
}
