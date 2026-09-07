<?php

namespace AiFace\WebSocket\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AiFaceApiAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = config('aiface.api.auth_token');

        if (!empty($expectedToken)) {
            $bearer = $request->bearerToken();
            $header = $request->header('X-AiFace-Token');

            if ($bearer !== $expectedToken && $header !== $expectedToken) {
                return response()->json([
                    'result' => false,
                    'error' => 'Unauthorized: Invalid or missing API token.',
                ], 401);
            }
        }

        return $next($request);
    }
}
