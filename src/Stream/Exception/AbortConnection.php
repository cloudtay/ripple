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
 * @internal
 *
 * Marker interface for internal exceptions that should trigger immediate connection termination.
 * Only the reactor's exception boundary should catch exceptions implementing this interface.
 */
interface AbortConnection
{
}
