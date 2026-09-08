<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\SettingsService;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Platform settings. Everything saved here is what the branding layer,
 * registration flow and system emails read at runtime.
 */
class SettingController extends Controller
{
    public function __construct(protected SettingsService $settings) {}

    public function branding(): View
    {
        return view('admin.settings.branding', [
            'values' => array_merge(SettingsService::DEFAULTS['branding'], $this->settings->group('branding')),
        ]);
    }

    public function updateBranding(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:191'],
            'website' => ['nullable', 'url', 'max:191'],
            'support_email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'footer_text' => ['nullable', 'string', 'max:191'],
            'email_footer' => ['nullable', 'string', 'max:255'],
            /*
             * The `image` rule and the `mimes` list used to contradict each
             * other: mimes offered svg and ico, `image` refuses both, so an
             * operator following the help text got a rejection that named a
             * format the screen had just recommended.
             *
             * SVG is left out deliberately rather than enabled with
             * `image:allow_svg`. An SVG is a document that can carry script,
             * and this file is served from our own origin to every signed-in
             * user of every tenant — a logo is not worth that.
             *
             * The favicon drops the `image` rule instead of dropping .ico:
             * `mimes` checks the real content type through fileinfo, not the
             * extension, and .ico is what most people actually have.
             */
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'file', 'mimes:png,ico,webp', 'max:512'],
            'remove_logo' => ['nullable', 'boolean'],
            'remove_favicon' => ['nullable', 'boolean'],
        ]);

        $current = $this->settings->group('branding');

        $values = collect($data)
            ->except(['logo', 'favicon', 'remove_logo', 'remove_favicon'])
            ->map(fn ($value) => $value ?? '')
            ->all();

        $values['logo_path'] = $this->handleUpload(
            $request, 'logo', 'remove_logo', $current['logo_path'] ?? ''
        );
        $values['favicon_path'] = $this->handleUpload(
            $request, 'favicon', 'remove_favicon', $current['favicon_path'] ?? ''
        );

        $this->settings->setMany('branding', $values);

        ActivityLogger::log('admin.settings.branding', 'Updated platform branding');

        return back()->with('success', 'Branding saved. The new look is live across the platform.');
    }

    public function system(): View
    {
        return view('admin.settings.system', [
            'values' => array_merge(SettingsService::DEFAULTS['system'], $this->settings->group('system')),
            'plans' => Plan::active()->ordered()->get(),
        ]);
    }

    public function updateSystem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'allow_registration' => ['boolean'],
            'require_email_verification' => ['boolean'],
            'default_plan_slug' => ['required', 'string', 'exists:plans,slug'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $this->settings->setMany('system', [
            'allow_registration' => $request->boolean('allow_registration') ? '1' : '0',
            'require_email_verification' => $request->boolean('require_email_verification') ? '1' : '0',
            'default_plan_slug' => $data['default_plan_slug'],
            'trial_days' => (string) $data['trial_days'],
        ]);

        ActivityLogger::log('admin.settings.system', 'Updated system settings');

        return back()->with('success', 'System settings saved.');
    }

    public function payment(): View
    {
        return view('admin.settings.payment', [
            'values' => array_merge(SettingsService::DEFAULTS['payment'], $this->settings->group('payment')),
        ]);
    }

    public function updatePayment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3'],
            'currency_symbol' => ['required', 'string', 'max:5'],
            'bank_details' => ['nullable', 'string', 'max:2000'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['currency'] = strtoupper($data['currency']);

        $this->settings->setMany('payment', array_map(fn ($v) => $v ?? '', $data));

        ActivityLogger::log('admin.settings.payment', 'Updated payment settings');

        return back()->with('success', 'Payment settings saved.');
    }

    /**
     * Stores an uploaded brand asset on the public disk, deleting whatever it
     * replaces so old logos do not accumulate.
     */
    protected function handleUpload(Request $request, string $field, string $removeField, string $currentPath): string
    {
        $disk = Storage::disk('public');

        if ($request->boolean($removeField)) {
            if ($currentPath && $disk->exists($currentPath)) {
                $disk->delete($currentPath);
            }

            return '';
        }

        if (! $request->hasFile($field)) {
            return $currentPath;
        }

        if ($currentPath && $disk->exists($currentPath)) {
            $disk->delete($currentPath);
        }

        return $request->file($field)->store('branding', 'public');
    }
}
