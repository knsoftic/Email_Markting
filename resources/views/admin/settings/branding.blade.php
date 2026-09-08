<x-admin-layout>
    <x-slot name="header">Branding</x-slot>

    <x-page-header title="Branding"
                   subtitle="These values drive the login page, sidebar, footer, system emails and brand colours everywhere." />

    <form method="POST" action="{{ route('admin.settings.branding.update') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf @method('PUT')

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Identity</h3></div>
            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="company_name" value="Company name" />
                    <x-text-input id="company_name" name="company_name" :value="old('company_name', $values['company_name'])" required />
                    <x-input-error :messages="$errors->get('company_name')" />
                </div>
                <div>
                    <x-input-label for="tagline" value="Tagline" />
                    <x-text-input id="tagline" name="tagline" :value="old('tagline', $values['tagline'])" />
                </div>
                <div>
                    <x-input-label for="website" value="Website" />
                    <x-text-input id="website" name="website" type="url" :value="old('website', $values['website'])" />
                    <x-input-error :messages="$errors->get('website')" />
                </div>
                <div>
                    <x-input-label for="support_email" value="Support email" />
                    <x-text-input id="support_email" name="support_email" type="email" :value="old('support_email', $values['support_email'])" />
                    <x-input-error :messages="$errors->get('support_email')" />
                </div>
                <div>
                    <x-input-label for="phone" value="Phone" />
                    <x-text-input id="phone" name="phone" :value="old('phone', $values['phone'])" />
                </div>
                <div>
                    <x-input-label for="address" value="Address" />
                    <x-text-input id="address" name="address" :value="old('address', $values['address'])" />
                </div>
            </div>
        </div>

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Logo &amp; favicon</h3></div>
            <div class="grid gap-6 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="logo" value="Logo" />
                    <div class="mb-3 flex h-16 items-center rounded-lg border border-dashed border-ink-300 bg-ink-50 px-4">
                        @if ($values['logo_path'] && Storage::disk('public')->exists($values['logo_path']))
                            <img src="{{ Storage::disk('public')->url($values['logo_path']) }}" alt="Logo" class="max-h-10">
                        @else
                            <span class="text-xs text-ink-400">No logo uploaded — the KN wordmark is used.</span>
                        @endif
                    </div>
                    <input id="logo" name="logo" type="file" accept=".png,.jpg,.jpeg,.webp" class="kn-input">
                    <p class="kn-help">PNG, JPG or WebP up to 2 MB. Shown at 36px tall.
                       SVG is not accepted: it can carry script, and this file is served to every
                       signed-in user.</p>
                    @if ($values['logo_path'])
                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-ink-600">
                            <input type="checkbox" name="remove_logo" value="1" class="kn-checkbox"> Remove current logo
                        </label>
                    @endif
                    <x-input-error :messages="$errors->get('logo')" />
                </div>

                <div>
                    <x-input-label for="favicon" value="Favicon" />
                    <div class="mb-3 flex h-16 items-center rounded-lg border border-dashed border-ink-300 bg-ink-50 px-4">
                        @if ($values['favicon_path'] && Storage::disk('public')->exists($values['favicon_path']))
                            <img src="{{ Storage::disk('public')->url($values['favicon_path']) }}" alt="Favicon" class="h-8 w-8">
                        @else
                            <span class="text-xs text-ink-400">No favicon uploaded.</span>
                        @endif
                    </div>
                    <input id="favicon" name="favicon" type="file" accept=".png,.ico,.webp" class="kn-input">
                    <p class="kn-help">PNG, ICO or WebP up to 512 KB.</p>
                    @if ($values['favicon_path'])
                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-ink-600">
                            <input type="checkbox" name="remove_favicon" value="1" class="kn-checkbox"> Remove current favicon
                        </label>
                    @endif
                    <x-input-error :messages="$errors->get('favicon')" />
                </div>
            </div>
        </div>

        <div class="kn-card" x-data="{ primary: '{{ old('primary_color', $values['primary_color']) }}', accent: '{{ old('accent_color', $values['accent_color']) }}' }">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Brand colours</h3></div>
            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="primary_color" value="Primary colour" />
                    <div class="flex gap-2">
                        <input type="color" x-model="primary" class="h-10 w-14 shrink-0 cursor-pointer rounded border border-ink-300">
                        <input id="primary_color" name="primary_color" x-model="primary" class="kn-input" required>
                    </div>
                    <p class="kn-help">Buttons, links, active navigation and charts.</p>
                    <x-input-error :messages="$errors->get('primary_color')" />
                </div>
                <div>
                    <x-input-label for="accent_color" value="Accent colour" />
                    <div class="flex gap-2">
                        <input type="color" x-model="accent" class="h-10 w-14 shrink-0 cursor-pointer rounded border border-ink-300">
                        <input id="accent_color" name="accent_color" x-model="accent" class="kn-input" required>
                    </div>
                    <x-input-error :messages="$errors->get('accent_color')" />
                </div>

                <div class="sm:col-span-2">
                    <p class="kn-stat-label mb-2">Preview</p>
                    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-ink-200 p-4">
                        <span class="rounded-lg px-4 py-2 text-sm font-semibold text-white" :style="`background:${primary}`">Primary button</span>
                        <span class="rounded-lg px-4 py-2 text-sm font-semibold text-white" :style="`background:${accent}`">Accent</span>
                        <span class="text-sm font-medium" :style="`color:${primary}`">Link colour</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="kn-card">
            <div class="kn-card-header"><h3 class="text-sm font-semibold text-ink-900">Footers</h3></div>
            <div class="grid gap-5 p-5 sm:grid-cols-2">
                <div>
                    <x-input-label for="footer_text" value="App footer text" />
                    <x-text-input id="footer_text" name="footer_text" :value="old('footer_text', $values['footer_text'])" />
                </div>
                <div>
                    <x-input-label for="email_footer" value="Default email footer" />
                    <x-text-input id="email_footer" name="email_footer" :value="old('email_footer', $values['email_footer'])" />
                    <p class="kn-help">Appended to system emails sent by the platform.</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="kn-btn-primary">Save branding</button>
        </div>
    </form>
</x-admin-layout>
