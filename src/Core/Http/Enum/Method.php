<?php declare(strict_types=1);

namespace Psc\Core\Http\Enum;

enum Method
{
    public const GET     = 'GET';
    public const POST    = 'POST';
    public const PUT     = 'PUT';
    public const DELETE  = 'DELETE';
    public const PATCH   = 'PATCH';
    public const OPTIONS = 'OPTIONS';
    public const HEAD    = 'HEAD';
    public const TRACE   = 'TRACE';
    public const CONNECT = 'CONNECT';
}
