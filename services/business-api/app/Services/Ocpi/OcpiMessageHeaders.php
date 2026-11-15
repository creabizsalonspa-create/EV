<?php

namespace App\Services\Ocpi;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Preserve peer-visible OCPI request tracing without reflecting unsafe headers. */
final class OcpiMessageHeaders
{
    public static function response(Request $request): array
    {
        $id = static fn (mixed $value): string => is_string($value) && preg_match('/^[!-~]{1,128}$/D', $value)
            ? $value : (string) Str::uuid();

        return [
            'X-Request-ID' => $id($request->header('X-Request-ID')),
            'X-Correlation-ID' => $id($request->header('X-Correlation-ID')),
        ];
    }
}
