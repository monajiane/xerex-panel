<?php

namespace App\Http\Middleware;

use App\Models\EdgeServer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an edge agent using a bearer token.
 * The agent token is generated when the edge server is created.
 */
class AuthenticateEdgeAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json([
                'error'   => 'unauthorized',
                'message' => 'Missing agent token',
            ], 401);
        }

        $edge = EdgeServer::where('agent_token', $token)->first();

        if (! $edge) {
            return response()->json([
                'error'   => 'unauthorized',
                'message' => 'Invalid agent token',
            ], 401);
        }

        if ($edge->agent_token_expires_at && $edge->agent_token_expires_at->isPast()) {
            return response()->json([
                'error'   => 'token_expired',
                'message' => 'Agent token has expired',
            ], 401);
        }

        if (! $edge->is_active) {
            return response()->json([
                'error'   => 'edge_disabled',
                'message' => 'Edge server is disabled',
            ], 403);
        }

        // Attach to request for downstream use
        $request->attributes->set('edge_server', $edge);
        $request->setUserResolver(fn () => null); // No user auth required for agent calls

        return $next($request);
    }
}
