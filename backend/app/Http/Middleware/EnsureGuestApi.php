<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureGuestApi
{
    /**
     * Handle an incoming request.
     * Prevents authenticated users from accessing guest API endpoints (login/register).
     * Returns JSON HTTP 400 instead of a web 302 redirect.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('web')->check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Bạn đã đăng nhập rồi.',
            ], 400);
        }

        return $next($request);
    }
}
