<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an account tries to exceed one of its plan limits.
 *
 * Renders as a redirect with a readable message for normal requests and as a
 * 403 JSON body for XHR, so a limit is never reported as a generic crash.
 */
class PlanLimitException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $limitKey = '',
        public readonly ?int $limit = null,
        public readonly int $used = 0,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'limit_key' => $this->limitKey,
                'limit' => $this->limit,
                'used' => $this->used,
            ], 403);
        }

        return back()->with('error', $this->getMessage());
    }
}
