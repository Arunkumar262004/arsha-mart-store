<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tokens are revoked when an admin deactivates a user, but this also
 * blocks any request that slips through in between.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->is_active === false, 403, 'This account has been deactivated.');

        return $next($request);
    }
}
