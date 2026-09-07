{{--
    Inline SMTP connection test.

    Include it once per account, passing the account id and the POST route:

        @include('smtp.partials.test-widget', [
            'testId'  => $smtp->id,
            'testUrl' => route('smtp.test', $smtp),
        ])

    Several copies can live on one page: each gets its own Alpine scope, so a
    test running on one account never touches another's panel.

    The endpoint answers 200 when the handshake works and 422 when it does not,
    and BOTH carry the same JSON body — so the status code is never the answer
    on its own. The body is parsed either way and only a missing/unparsable
    body falls back to a transport-level message.
--}}

@php $testId = $testId ?? 0; @endphp
@php $testLabel = $testLabel ?? 'Test connection'; @endphp

<div x-data="knSmtpTest(@js($testUrl))" class="min-w-0">
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="kn-btn-secondary kn-btn-sm" @click="run()" :disabled="loading"
                :aria-busy="loading ? 'true' : 'false'"
                aria-describedby="smtp-test-result-{{ $testId }}">
            <svg x-show="loading" x-cloak class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                <path class="opacity-90" fill="currentColor" d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7V2Z"/>
            </svg>
            <span x-text="loading ? 'Testing…' : (result ? 'Test again' : @js($testLabel))">{{ $testLabel }}</span>
        </button>

        <span x-show="loading" x-cloak class="text-xs text-ink-500">
            Connecting, sending EHLO and authenticating — no email is sent.
        </span>
    </div>

    <div id="smtp-test-result-{{ $testId }}" role="status" aria-live="polite" class="min-w-0">

        {{-- ------------------------------------------------------ success --}}
        <div x-show="result && result.ok" x-cloak
             class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-sm font-semibold text-emerald-800" x-text="result ? result.summary : ''"></p>
            <p class="mt-1 break-words text-xs text-emerald-700" x-text="result ? result.detail : ''"></p>
            <p class="mt-2 text-xs text-emerald-600">
                Tested <span x-text="result ? result.tested_at : ''"></span>. The stored status on this page
                updates the next time you load it.
            </p>
        </div>

        {{-- ------------------------------------------------------ failure --}}
        <div x-show="result && ! result.ok" x-cloak
             class="mt-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3">
            <p class="text-sm font-semibold text-red-800" x-text="result ? result.summary : ''"></p>

            <p class="mt-1 text-xs text-red-700">
                <span x-show="result && result.code" x-cloak>
                    SMTP code <span x-text="result ? result.code : ''"></span> ·
                </span>
                <span x-show="result && result.tested_at" x-cloak>
                    Tested <span x-text="result ? result.tested_at : ''"></span>
                </span>
            </p>

            <button type="button" x-show="result && result.detail" x-cloak
                    @click="showDetail = ! showDetail"
                    :aria-expanded="showDetail ? 'true' : 'false'"
                    class="mt-2 text-xs font-semibold text-red-700 underline underline-offset-2 hover:text-red-900"
                    x-text="showDetail ? 'Hide technical detail' : 'Show technical detail'"></button>

            <pre x-show="showDetail" x-cloak
                 class="mt-2 max-h-56 overflow-auto whitespace-pre-wrap break-words rounded bg-red-100/70 p-3 text-xs text-red-900"
                 x-text="result ? result.detail : ''"></pre>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            @verbatim
            /**
             * Alpine component behind the SMTP test button.
             *
             * A classic script so the factory exists before Alpine.start()
             * runs from the deferred module bundle.
             */
            window.knSmtpTest = (url) => ({
                url: url,
                loading: false,
                showDetail: false,
                result: null,

                csrfToken() {
                    const meta = document.querySelector('meta[name="csrf-token"]');

                    return meta ? meta.getAttribute('content') : '';
                },

                async run() {
                    if (this.loading) return;

                    this.loading = true;
                    this.showDetail = false;
                    this.result = null;

                    try {
                        const response = await fetch(this.url, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': this.csrfToken(),
                            },
                            body: '{}',
                        });

                        // 200 = handshake passed, 422 = handshake failed. Both
                        // carry the result body, so it is read before the
                        // status is ever consulted.
                        let data = null;

                        try {
                            data = await response.json();
                        } catch (parseFailure) {
                            data = null;
                        }

                        if (data && typeof data.ok === 'boolean') {
                            this.result = {
                                ok: data.ok,
                                summary: data.summary || (data.ok ? 'Connected successfully.' : 'The connection failed.'),
                                detail: data.detail || '',
                                code: data.code || null,
                                ms: data.ms || null,
                                tested_at: data.tested_at || '',
                            };
                        } else if (response.status === 419) {
                            this.result = {
                                ok: false,
                                summary: 'Your session expired before the test could run.',
                                detail: 'Reload the page to get a fresh session, then test again.',
                                code: 419,
                                ms: null,
                                tested_at: '',
                            };
                        } else {
                            this.result = {
                                ok: false,
                                summary: 'The test could not be started (HTTP ' + response.status + ').',
                                detail: (data && data.message) ? data.message : 'The server did not return a test result.',
                                code: response.status,
                                ms: null,
                                tested_at: '',
                            };
                        }
                    } catch (failure) {
                        this.result = {
                            ok: false,
                            summary: 'The browser could not reach the server to run the test.',
                            detail: failure && failure.message ? failure.message : 'The request never completed. Check your connection and try again.',
                            code: null,
                            ms: null,
                            tested_at: '',
                        };
                    } finally {
                        this.loading = false;
                    }
                },
            });
            @endverbatim
        </script>
    @endpush
@endonce
