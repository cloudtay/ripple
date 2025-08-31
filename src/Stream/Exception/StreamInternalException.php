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

namespace Ripple\Stream\Exception;

use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * 框架内部异常 - 用于内部控制流
 * 
 * 警告：此异常用于框架内部控制流，应用代码不应捕获此异常！
 * 当底层 I/O 操作失败时，此异常会穿透作用域到框架的兜底区域进行连接清理。
 * 
 * @Author cclilshy
 * @Date   2024/8/16 09:37
 */
final class StreamInternalException extends Exception
{
    public const CONNECTION_ERROR      = 1;
    public const CONNECTION_READ_FAIL  = 16;
    public const CONNECTION_WRITE_FAIL = 8;

    public function __construct(
        public $message = "",
        public $code = 0,
        public readonly Throwable|null       $previous = null,
        public readonly StreamInterface|null $stream = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}