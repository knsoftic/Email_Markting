<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response should carry.
 *
 * ── The one that was actually missing something ─────────────────────────────
 * Without `frame-ancestors` an attacker can load the sign-in page inside an
 * iframe on their own site, cover it with their own interface, and collect a
 * click — or a password — that the person believed they were giving to us.
 * Nothing in the application can detect that from the inside; the only defence
 * is telling the browser not to allow the frame at all.
 *
 * ── Why some responses are deliberately skipped ─────────────────────────────
 * Three routes serve email HTML into a `sandbox=""` iframe on our own page —
 * the inbox body, the campaign preview and the template preview — and each
 * already sends a much stricter policy of its own, built for exactly that job.
 * Overwriting it here, or adding `X-Frame-Options: DENY` on top, would stop the
 * application rendering its own mail: DENY blocks even a same-origin frame.
 *
 * So a response that has already declared a `Content-Security-Policy` keeps it
 * untouched. It knows more about what it is than this middleware does. The
 * harmless headers are still added to it.
 *
 * ── An honest note about script-src ─────────────────────────────────────────
 * `script-src` is `'self' 'unsafe-inline' 'unsafe-eval'`, and that is not much
 * of an XSS defence. Alpine evaluates its expressions with the `Function`
 * constructor, so it needs `unsafe-eval`, and seven views carry inline
 * `<script>` blocks. Tightening it means moving to nonces and an Alpine CSP
 * build — worth doing, not something to claim is done.
 *
 * What it does buy, today, is real: an injected `<script src="//evil.example">`
 * is refused, `object-src 'none'` kills plugin-based payloads, `base-uri 'self'`
 * stops a `<base>` tag redirecting every relative URL on the page, and
 * `form-action 'self'` stops an injected form posting a password somewhere else.
 */
class SecurityHeaders
{
    /**
     * The application's own policy, for pages this application renders.
     *
     * @var array<string, string>
     */
    protected const POLICY = [
        'default-src' => "'self'",
        'base-uri' => "'self'",
        'form-action' => "'self'",
        'frame-ancestors' => "'none'",
        'object-src' => "'none'",
        // See the note above: honest rather than aspirational.
        'script-src' => "'self' 'unsafe-inline' 'unsafe-eval'",
        // Tailwind emits a stylesheet; the font service serves one.
        'style-src' => "'self' 'unsafe-inline' https://fonts.bunny.net",
        'font-src' => "'self' data: https://fonts.bunny.net",
        // Avatars fall back to ui-avatars.com, and template thumbnails and
        // contact-supplied logos can come from anywhere. Images are the one
        // place a broad allowance costs little.
        'img-src' => "'self' data: https:",
        'connect-src' => "'self'",
        // The inbox body and the two previews, all same-origin.
        'frame-src' => "'self'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;

        /*
         * Defaults, never overrides. Every header here is only set if the
         * response did not set one itself.
         *
         * This is not tidiness. The inbox body route deliberately sends
         * `Referrer-Policy: no-referrer`, because a remote image inside a
         * stranger's email would otherwise tell its host which page of this
         * application was open. A middleware that "helpfully" replaced it with
         * the site-wide default undid that — which is exactly what happened,
         * and what the inbox's own test caught.
         */
        $this->default($headers, 'X-Content-Type-Options', 'nosniff');
        $this->default($headers, 'Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->default($headers, 'X-Permitted-Cross-Domain-Policies', 'none');

        // Switched off rather than left unstated. Nothing here uses a camera,
        // a microphone or a location, so saying so removes them from any
        // embedded content too.
        $this->default(
            $headers,
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()'
        );

        // A response that set its own policy is left alone — see the class note.
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->policy());
            $this->default($headers, 'X-Frame-Options', 'DENY');
        }

        return $response;
    }

    /** Sets a header only where the response has not already spoken for itself. */
    protected function default(ResponseHeaderBag $headers, string $name, string $value): void
    {
        if (! $headers->has($name)) {
            $headers->set($name, $value);
        }
    }

    protected function policy(): string
    {
        $policy = self::POLICY;

        /*
         * Vite serves its client and its modules from a dev server on another
         * port while `npm run dev` is running, so a policy built for production
         * would make local development impossible.
         *
         * These EXTEND their directives rather than being appended as new ones.
         * A CSP that names the same directive twice is not additive — the
         * browser honours the first and silently ignores the rest — so an
         * appended second `connect-src` would have left the original in force
         * and blocked the dev server's websocket, with nothing in the header to
         * explain why.
         */
        if (app()->environment('local') && $this->viteIsRunning()) {
            /*
             * All three spellings of the same machine, because CSP matches the
             * host literally and Vite does not always advertise the one you
             * expect: on this setup it serves from `http://[::1]:5173`, the
             * IPv6 loopback, so a policy naming only `localhost` blocked every
             * script and stylesheet and left the developer with a blank page
             * and a console full of CSP errors.
             */
            $origins = [
                'http://localhost:5173', 'ws://localhost:5173',
                'http://127.0.0.1:5173', 'ws://127.0.0.1:5173',
                'http://[::1]:5173', 'ws://[::1]:5173',
            ];

            $http = implode(' ', array_filter($origins, fn ($o) => str_starts_with($o, 'http')));
            $all = implode(' ', $origins);

            $policy['script-src'] .= ' '.$http;
            $policy['style-src'] .= ' '.$http;
            $policy['connect-src'] .= ' '.$all;
        }

        $directives = [];

        foreach ($policy as $directive => $value) {
            $directives[] = $directive.' '.$value;
        }

        return implode('; ', $directives);
    }

    /** Whether Vite's dev server is the one serving assets right now. */
    protected function viteIsRunning(): bool
    {
        return is_file(public_path('hot'));
    }
}
