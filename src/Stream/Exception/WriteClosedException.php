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

/**
 * Exception thrown when attempting to write to a write-closed stream
 * This is a recoverable exception that indicates the write side has been shut down
 */
class WriteClosedException extends TransportException
{
}
