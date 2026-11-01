<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Internal actions (creating invoices, confirming payments…) that a client login may never do. */
class StaffOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isClient()) {
            return response()->json(['message' => 'This action is for DigiFox staff only.', 'code' => 'FORBIDDEN'], 403);
        }

        return $next($request);
    }
}
