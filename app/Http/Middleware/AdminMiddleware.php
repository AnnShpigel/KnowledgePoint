<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        //!auth()->check() || !auth()->user()->isAdmin() ||
        if ($request->user()->role !== 'admin') {
            return response()->json(['error' => 'Требуются права администратора'], 403);
        }

        return $next($request);
    }
}
