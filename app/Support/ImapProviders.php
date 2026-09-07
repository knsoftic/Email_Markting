<?php

namespace App\Support;

/**
 * IMAP connection presets, the sibling of SmtpProviders.
 *
 * A preset only pre-fills the form; nothing here is trusted at sync time. The
 * notes are the parts people actually get wrong, and for IMAP that is almost
 * always the same thing: the big providers stopped accepting ordinary account
 * passwords years ago, and the error they return says only "authentication
 * failed".
 */
class ImapProviders
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'gmail' => [
                'label' => 'Gmail / Google Workspace',
                'host' => 'imap.gmail.com',
                'port' => 993,
                'encryption' => 'ssl',
                'username_hint' => 'Your full Gmail address',
                'password_hint' => 'A 16-character App Password, not your account password',
                'note' => 'IMAP must be switched on in Gmail: Settings → See all settings → Forwarding and '
                    .'POP/IMAP → Enable IMAP. The password field needs an App Password, which requires 2-Step '
                    .'Verification to be on first.',
            ],

            'microsoft' => [
                'label' => 'Outlook / Microsoft 365',
                'host' => 'outlook.office365.com',
                'port' => 993,
                'encryption' => 'ssl',
                'username_hint' => 'Your full Outlook or Microsoft 365 address',
                'password_hint' => 'An App Password if 2-step verification is on',
                'note' => 'Microsoft disables IMAP by default on many work and school tenants — if the connection '
                    .'is refused after a correct password, ask the administrator to enable IMAP for the mailbox.',
            ],

            'yahoo' => [
                'label' => 'Yahoo Mail',
                'host' => 'imap.mail.yahoo.com',
                'port' => 993,
                'encryption' => 'ssl',
                'username_hint' => 'Your full Yahoo address',
                'password_hint' => 'An App Password generated in Account Security',
                'note' => 'Yahoo never accepts the normal account password over IMAP. Generate an App Password '
                    .'under Account Security → Generate app password.',
            ],

            'zoho' => [
                'label' => 'Zoho Mail',
                'host' => 'imap.zoho.com',
                'port' => 993,
                'encryption' => 'ssl',
                'username_hint' => 'Your full Zoho address',
                'password_hint' => 'Account password, or an app-specific password with 2FA on',
                'note' => 'IMAP access has to be enabled per mailbox in Zoho Mail settings. Accounts on a regional '
                    .'data centre use a different host — imap.zoho.eu, imap.zoho.in and so on.',
            ],

            'icloud' => [
                'label' => 'iCloud Mail',
                'host' => 'imap.mail.me.com',
                'port' => 993,
                'encryption' => 'ssl',
                'username_hint' => 'The part of your iCloud address before the @',
                'password_hint' => 'An app-specific password from appleid.apple.com',
                'note' => 'iCloud requires an app-specific password and two-factor authentication. The username '
                    .'is usually the local part only, not the full address.',
            ],

            'fastmail' => [
                'label' => 'Fastmail',
                'host' => 'imap.fastmail.com',
                'port' => 993,
                'encryption' => 'ssl',
                'username_hint' => 'Your full Fastmail address',
                'password_hint' => 'An app password created in Settings → Privacy & Security',
                'note' => 'Fastmail requires an app password with the Mail (IMAP) scope; the login password '
                    .'will not work.',
            ],

            'custom' => [
                'label' => 'Other / custom IMAP server',
                'host' => '',
                'port' => 993,
                'encryption' => 'ssl',
                'username_hint' => 'Usually the full email address',
                'password_hint' => 'The mailbox password',
                'note' => 'Port 993 goes with SSL; port 143 goes with TLS/STARTTLS. Mixing them is the single '
                    .'most common reason a connection fails.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        return self::all()[$key] ?? self::all()['custom'];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
