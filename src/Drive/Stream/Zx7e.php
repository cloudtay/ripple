<?php declare(strict_types=1);

namespace Psc\Drive\Stream;

use function chr;
use function ord;
use function pack;
use function strlen;
use function strpos;
use function substr;
use function unpack;

/**
 *
 */
class Zx7e
{
    /**
     *
     */
    private const int FRAME_HEADER = 0x7E;

    /**
     *
     */
    private const int FRAME_FOOTER = 0x7E;

    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * @param string $data
     * @return string
     */
    public function encodeFrame(string $data): string
    {
        $length   = strlen($data);
        $checksum = $this->calculateChecksum($data);

        $frame = chr(self::FRAME_HEADER);
        $frame .= pack('n', $length);
        $frame .= $data;
        $frame .= chr($checksum);
        $frame .= chr(self::FRAME_FOOTER);

        return $frame;
    }

    /**
     * @param string $data
     * @return array
     */
    public function decodeStream(string $data): array
    {
        $this->buffer .= $data;
        $frames       = [];

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
        $startPos = strpos($this->buffer, chr(self::FRAME_HEADER));

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

        if ($endPos >= strlen($this->buffer) || ord($this->buffer[$endPos]) !== self::FRAME_FOOTER) {
            $this->buffer = substr($this->buffer, $endPos);
            return null;
        }

        $this->buffer = substr($this->buffer, $endPos + 1);

        if ($checksum !== $this->calculateChecksum($data)) {
            return null;
        }

        return $data;
    }

    /**
     * @param string $data
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
}
