<?php

namespace App\Http\Requests\Imap;

use App\Models\Scopes\AccountScope;
use App\Models\SmtpAccount;
use App\Services\Smtp\SmtpSelector;
use App\Support\ImapProviders;
use App\Support\PlanLimits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MailboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('mailboxes.manage') ?? false;
    }

    /**
     * The SMTP account ids this tenant may actually send through.
     *
     * @return list<int>
     */
    protected function allowedSmtpAccountIds(): array
    {
        $account = $this->user()?->account;

        if (! $account) {
            return [];
        }

        $limits = PlanLimits::for($account);

        $own = $limits->allows('allow_custom_smtp')
            ? SmtpAccount::withoutGlobalScope(AccountScope::class)
                ->where('account_id', $account->id)
                ->where('is_global', false)
                ->pluck('id')
                ->all()
            : [];

        $shared = app(SmtpSelector::class)->visibleSharedFor($account)->pluck('id')->all();

        return array_values(array_unique([...$own, ...$shared]));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $mailbox = $this->route('mailbox');
        $creating = $mailbox === null;
        $accountId = $this->user()->account_id;

        return [
            'name' => ['required', 'string', 'max:191'],

            // One row per address per account: two mailboxes pointing at the
            // same server would sync the same messages twice and the unique
            // index on (mailbox_id, message_id) would not stop it.
            'email' => ['required', 'email:rfc', 'max:191',
                Rule::unique('mailboxes', 'email')
                    ->where('account_id', $accountId)
                    ->whereNull('deleted_at')
                    ->ignore($mailbox?->id)],

            'provider' => ['required', Rule::in(ImapProviders::keys())],

            'imap_host' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'imap_encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
            'imap_username' => ['required', 'string', 'max:191'],

            // Required on create; blank on edit means "keep the stored one", so
            // an operator can fix a typo in the host without re-typing an app
            // password they may not have to hand.
            'imap_password' => [$creating ? 'required' : 'nullable', 'string', 'max:500'],
            'imap_validate_cert' => ['boolean'],

            // The SMTP account replies from this mailbox go out through.
            //
            // `is_global = true` used to be enough on its own, which let any
            // tenant point its replies at any platform relay on the
            // installation — no assignment, and not even allow_admin_smtp.
            // Replies are outbound mail like everything else, so this is held
            // to exactly the rule the campaign sender uses: own accounts if the
            // plan allows them, and a platform account only where an assignment
            // reaches this tenant.
            'smtp_account_id' => ['nullable', 'integer',
                Rule::in($this->allowedSmtpAccountIds())],

            'sync_enabled' => ['boolean'],
            'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'sync_limit' => ['required', 'integer', 'min:1', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'imap_host.regex' => 'The host must be a hostname such as imap.example.com, without a scheme or path.',
            'imap_password.required' => 'A password is required to sign in to the mailbox.',
            'email.unique' => 'A mailbox for that address already exists in this account.',
            'sync_limit.max' => 'A single pass fetches at most 500 messages; the next pass continues from there.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim($this->filter('email') ?? '')),
            'imap_host' => mb_strtolower(trim($this->filter('imap_host') ?? '')),
            'imap_validate_cert' => $this->boolean('imap_validate_cert'),
            'sync_enabled' => $this->boolean('sync_enabled'),
            'is_active' => $this->boolean('is_active'),
            'smtp_account_id' => $this->filter('smtp_account_id') ?: null,
        ]);
    }

    /**
     * The fields that go on the row, with the password dropped when the form
     * left it blank.
     *
     * @return array<string, mixed>
     */
    public function persistable(): array
    {
        $data = $this->validated();

        if (blank($data['imap_password'] ?? null)) {
            unset($data['imap_password']);
        }

        return $data;
    }
}
