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

namespace Ripple\Worker;

use Ripple\Process\Runtime;
use Ripple\Stream;
use Ripple\Utils\Serialization\Zx7e;

use function time;
use function get_current_user;

class WorkerProcess
{
    /**
     * @var \Ripple\Utils\Serialization\Zx7e
     */
    private Zx7e $zx7e;

    /**
     * @param \Ripple\Process\Runtime $runtime
     * @param \Ripple\Stream          $stream
     * @param int                     $index
     */
    public function __construct(private readonly Runtime $runtime, private readonly Stream $stream, private readonly int $index)
    {
        $this->zx7e = new Zx7e();
        $this->metadata = [
            'pid'        => $this->runtime->getProcessID(),
            'index'      => $this->index,
            'start_time' => time(),
            'user'       => get_current_user(),
        ];
    }

    /**
     * @return \Ripple\Process\Runtime
     */
    public function getRuntime(): Runtime
    {
        return $this->runtime;
    }

    /**
     * @return \Ripple\Stream
     */
    public function getStream(): Stream
    {
        return $this->stream;
    }

    /**
     * @return int
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * @param \Ripple\Worker\Command $command
     *
     * @return void
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    public function command(Command $command): void
    {
        $this->stream->write($this->zx7e->encodeFrame($command->__toString()));
    }

    /**
     * @var array
     */
    private array $metadata = [];

    /**
     * @return array
     */
    public function getMetadate(): array
    {
        return $this->metadata;
    }

    /**
     * @param array $metadata
     *
     * @return void
     */
    public function refreshMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            $this->metadata[$key] = $value;
        }
    }
}
