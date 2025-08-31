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

namespace Ripple\Stream;

/**
 * Enumeration of connection abort reasons
 */
enum ConnectionAbortReason: string
{
    case PEER_CLOSED = 'peer_closed';
    case PEER_READ_CLOSED = 'peer_read_closed';
    case RESET = 'reset';
    case TLS_FATAL = 'tls_fatal';
    case LOCAL_CLOSE = 'local_close';
    case TIMEOUT = 'timeout';
    case IDLE_TIMEOUT = 'idle_timeout';
    case PROTOCOL_ERROR = 'protocol_error';
    case WRITE_FAILURE = 'write_failure';
    case HANDSHAKE_FAILURE = 'handshake_failure';
}
