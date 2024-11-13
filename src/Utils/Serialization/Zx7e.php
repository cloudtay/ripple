<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Utils\Serialization;

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
