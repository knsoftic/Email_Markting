<?php

namespace App\Support;

/**
 * Connection presets for the providers the brief names.
 *
 * A preset only pre-fills the form — the user can still change any field, and
 * nothing here is trusted at send time. The notes are the parts people
 * actually get wrong (Gmail needing an app password, SendGrid's username
 * being the literal word "apikey", SES credentials not being your AWS keys).
 */
class SmtpProviders
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'gmail' => [
                'label' => 'Gmail / Google Workspace',
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'encryption' => 'tls',
                'username_hint' => 'Your full Gmail address',
                'password_hint' => 'A 16-character App Password, not your account password',
                'notes' => 'Google requires 2-Step Verification to be on, then an App Password generated at myaccount.google.com/apppasswords. A normal password will be rejected. Free Gmail accounts are limited to roughly 500 recipients a day; Workspace to about 2,000.',
                'docs' => 'https://support.google.com/mail/answer/7126229',
                'suggested_daily_limit' => 500,
            ],

            'microsoft' => [
                'label' => 'Microsoft 365 / Outlook',
                'host' => 'smtp.office365.com',
                'port' => 587,
                'encryption' => 'tls',
                'username_hint' => 'Your full Microsoft 365 address',
                'password_hint' => 'Account password, or an app password when MFA is on',
                'notes' => 'SMTP AUTH is disabled by default on Microsoft 365 tenants and has to be enabled per mailbox. Personal Outlook.com accounts use smtp-mail.outlook.com instead. Microsoft throttles at about 10,000 recipients a day and 30 messages a minute.',
                'docs' => 'https://learn.microsoft.com/exchange/mail-flow-best-practices/how-to-set-up-a-multifunction-device-or-application-to-send-email-using-microsoft-365-or-office-365',
                'suggested_daily_limit' => 2000,
            ],

            'zoho' => [
                'label' => 'Zoho Mail',
                'host' => 'smtp.zoho.com',
                'port' => 587,
                'encryption' => 'tls',
                'username_hint' => 'Your full Zoho address',
                'password_hint' => 'An application-specific password',
                'notes' => 'Zoho regional data centres use different hosts — smtp.zoho.eu, smtp.zoho.in, smtp.zoho.com.au. Use the one matching where your account was created, or authentication fails with a correct password.',
                'docs' => 'https://www.zoho.com/mail/help/zoho-smtp.html',
                'suggested_daily_limit' => 300,
            ],

            'ses' => [
                'label' => 'Amazon SES',
                'host' => 'email-smtp.us-east-1.amazonaws.com',
                'port' => 587,
                'encryption' => 'tls',
                'username_hint' => 'SES SMTP username (starts with AKIA…)',
                'password_hint' => 'SES SMTP password — generated in SES, not your AWS secret key',
                'notes' => 'Change the region in the host to match where your SES identity lives. SMTP credentials are created in the SES console and are NOT your AWS access keys. A new account is in the sandbox and can only send to verified addresses until you request production access.',
                'docs' => 'https://docs.aws.amazon.com/ses/latest/dg/smtp-credentials.html',
                'suggested_daily_limit' => null,
            ],

            'sendgrid' => [
                'label' => 'SendGrid',
                'host' => 'smtp.sendgrid.net',
                'port' => 587,
                'encryption' => 'tls',
                'username_hint' => 'The literal word: apikey',
                'password_hint' => 'Your SendGrid API key (starts with SG.)',
                'notes' => 'The username is always the literal string "apikey" — not your email and not your key. The API key goes in the password field and needs Mail Send permission. Your sender address must be verified first.',
                'docs' => 'https://www.twilio.com/docs/sendgrid/for-developers/sending-email/integrating-with-the-smtp-api',
                'suggested_daily_limit' => null,
            ],

            'mailgun' => [
                'label' => 'Mailgun',
                'host' => 'smtp.mailgun.org',
                'port' => 587,
                'encryption' => 'tls',
                'username_hint' => 'postmaster@your-domain.com',
                'password_hint' => 'The SMTP password from your Mailgun domain settings',
                'notes' => 'EU-region domains use smtp.eu.mailgun.org. The credentials are per sending domain, found under Sending → Domain settings → SMTP credentials — not your Mailgun account login.',
                'docs' => 'https://documentation.mailgun.com/docs/mailgun/user-manual/sending-messages/',
                'suggested_daily_limit' => null,
            ],

            'brevo' => [
                'label' => 'Brevo (formerly Sendinblue)',
                'host' => 'smtp-relay.brevo.com',
                'port' => 587,
                'encryption' => 'tls',
                'username_hint' => 'Your Brevo login email',
                'password_hint' => 'An SMTP key from Brevo, not your account password',
                'notes' => 'Generate the SMTP key under SMTP & API → SMTP. The free tier allows about 300 emails a day. Your sender address has to be verified in Brevo before anything will go out.',
                'docs' => 'https://help.brevo.com/hc/en-us/articles/7924908994450',
                'suggested_daily_limit' => 300,
            ],

            'custom' => [
                'label' => 'Custom SMTP / cPanel / business email',
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'username_hint' => 'Usually the full email address',
                'password_hint' => 'The mailbox password',
                'notes' => 'Use the details your host gave you. Port 587 with STARTTLS is the modern default; 465 is implicit SSL; 25 is usually blocked by hosting providers and should be avoided.',
                'docs' => '',
                'suggested_daily_limit' => null,
            ],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        return self::all()[$key] ?? self::all()['custom'];
    }

    public static function label(string $key): string
    {
        return self::get($key)['label'];
    }

    /**
     * Ports we accept. 25 is deliberately allowed but never suggested — some
     * self-hosted relays genuinely use it.
     *
     * @return array<int, int>
     */
    public static function commonPorts(): array
    {
        return [25, 465, 587, 2525];
    }
}
