<?php

namespace App\Http\Requests\Smtp;

use App\Support\SmtpProviders;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SmtpAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Admin-side requests are already behind the super.admin middleware;
        // tenant-side ones need the smtp.manage permission.
        return $this->user()?->isSuperAdmin()
            || ($this->user()?->hasPermission('smtp.manage') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $account = $this->route('smtp_account') ?? $this->route('smtpAccount');
        $creating = $account === null;

        return [
            'name' => ['required', 'string', 'max:191'],
            'provider' => ['required', Rule::in(SmtpProviders::keys())],

            'host' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:191'],
            // Required on create; blank on edit means "keep the stored one",
            // so an operator can fix a typo in the host without re-typing the
            // credential they may not have to hand.
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:500'],
            'verify_peer' => ['boolean'],

            'from_name' => ['required', 'string', 'max:191'],
            'from_email' => ['required', 'email:rfc', 'max:191'],
            'reply_to' => ['nullable', 'email:rfc', 'max:191'],

            'hourly_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'daily_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'monthly_limit' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'send_delay_ms' => ['nullable', 'integer', 'min:0', 'max:60000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'host.regex' => 'The host must be a hostname such as smtp.example.com, without a scheme or path.',
            'password.required' => 'A password or API key is required to connect.',
        ];
    }

    public function attributes(): array
    {
        return [
            'from_email' => 'sender email',
            'from_name' => 'sender name',
            'reply_to' => 'reply-to address',
        ];
    }

    protected function prepareForValidation(): void
    {
        $host = trim((string) $this->input('host'));

        // People paste "smtp://host:587" or "https://smtp.host.com". Strip the
        // scheme, any path and a trailing port rather than failing them on it.
        $host = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $host) ?? $host;
        $host = explode('/', $host)[0];

        $port = $this->input('port');

        if (str_contains($host, ':')) {
            [$host, $inlinePort] = explode(':', $host, 2);
            $port = $port ?: $inlinePort;
        }

        $this->merge([
            'host' => rtrim($host, '.'),
            'port' => $port ?: 587,
            'from_email' => mb_strtolower(trim((string) $this->input('from_email'))),
            'reply_to' => $this->input('reply_to') ? mb_strtolower(trim((string) $this->input('reply_to'))) : null,
            'verify_peer' => $this->boolean('verify_peer', true),
            'is_active' => $this->boolean('is_active', true),
            'send_delay_ms' => $this->input('send_delay_ms') ?: 0,
            'priority' => $this->input('priority') ?: 0,
        ]);

        // Blank limit inputs mean unlimited, not zero.
        foreach (['hourly_limit', 'daily_limit', 'monthly_limit'] as $key) {
            if ($this->input($key) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * The attributes to persist. The password is dropped when left blank on an
     * edit so the stored (encrypted) credential survives.
     *
     * @return array<string, mixed>
     */
    public function persistable(): array
    {
        $data = $this->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        return $data;
    }
}
