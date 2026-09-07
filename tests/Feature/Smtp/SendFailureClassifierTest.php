<?php

namespace Tests\Feature\Smtp;

use App\Models\SmtpAccount;
use App\Services\Smtp\SendFailure;
use App\Services\Smtp\SendFailureClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\TestCase;

/**
 * The single most consequential judgement in the sending path: is this the
 * SMTP account's fault, or this one recipient's?
 *
 * Get it wrong one way and a single mistyped address takes a working provider
 * out of service for every campaign. Get it wrong the other way and a genuine
 * hard bounce is retried forever while the provider's reputation suffers.
 */
class SendFailureClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected SendFailureClassifier $classifier;

    protected SmtpAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = app(SendFailureClassifier::class);

        $this->account = SmtpAccount::factory()->make([
            'provider' => 'custom',
            'host' => 'smtp.example.test',
            'port' => 587,
            'username' => 'mailer@example.test',
            'password' => 'top-secret-key',
        ]);
    }

    /** Builds an exception carrying a realistic Symfony debug dialogue. */
    protected function exception(string $message, array $sentCommands = []): TransportException
    {
        $e = new UnexpectedResponseException($message);

        $debug = '';
        foreach ($sentCommands as $command) {
            $debug .= "> {$command}\r\n< 250 OK\r\n";
        }

        if ($debug !== '') {
            $e->appendDebug($debug);
        }

        return $e;
    }

    // ------------------------------------------------------ recipient level

    public function test_a_permanent_rejection_of_the_recipient_is_a_hard_bounce(): void
    {
        $failure = $this->classifier->classify(
            $this->exception(
                'Expected response code "250" but got code "550", with message "550 5.1.1 <ghost@example.com>: Recipient address rejected: User unknown".',
                ['EHLO app.test', 'MAIL FROM:<me@example.test>', 'RCPT TO:<ghost@example.com>']
            ),
            $this->account
        );

        $this->assertSame(SendFailure::RECIPIENT_HARD, $failure->kind);
        $this->assertSame(550, $failure->code);
        $this->assertTrue($failure->suppressRecipient, 'A dead mailbox must be suppressed.');
        $this->assertFalse($failure->retryable);
        $this->assertSame('hard', $failure->bounceType());
    }

    public function test_a_temporary_rejection_of_the_recipient_is_a_soft_bounce(): void
    {
        $failure = $this->classifier->classify(
            $this->exception(
                'Expected response code "250" but got code "452", with message "452 4.2.2 Mailbox full".',
                ['MAIL FROM:<me@example.test>', 'RCPT TO:<full@example.com>']
            ),
            $this->account
        );

        $this->assertSame(SendFailure::RECIPIENT_SOFT, $failure->kind);
        $this->assertTrue($failure->retryable);
        $this->assertFalse(
            $failure->suppressRecipient,
            'A full mailbox is temporary — suppressing it would lose a real contact.'
        );
        $this->assertSame('soft', $failure->bounceType());
    }

    public function test_one_bad_recipient_never_takes_the_account_out_of_service(): void
    {
        $failure = $this->classifier->classify(
            $this->exception(
                'Expected response code "250" but got code "550", with message "550 No such user".',
                ['MAIL FROM:<me@example.test>', 'RCPT TO:<typo@example.com>']
            ),
            $this->account
        );

        $this->assertFalse(
            $failure->isAccountLevel(),
            'A recipient-level failure must not count towards the account cooldown.'
        );
    }

    // -------------------------------------------------------- account level

    public function test_the_same_code_at_mail_from_is_the_sender_not_the_recipient(): void
    {
        $failure = $this->classifier->classify(
            $this->exception(
                'Expected response code "250" but got code "550", with message "550 5.7.1 Sender address not verified".',
                ['EHLO app.test', 'MAIL FROM:<unverified@example.test>']
            ),
            $this->account
        );

        $this->assertSame(SendFailure::MESSAGE, $failure->kind);
        $this->assertFalse($failure->suppressRecipient, 'The recipient did nothing wrong.');
        $this->assertFalse($failure->retryable);
        $this->assertStringContainsString('sender address', $failure->summary);
    }

    public function test_bad_credentials_are_an_account_failure_and_not_retryable(): void
    {
        $failure = $this->classifier->classify(
            $this->exception(
                'Expected response code "235" but got code "535", with message "535 5.7.8 Authentication credentials invalid".',
                ['EHLO app.test', 'AUTH LOGIN']
            ),
            $this->account
        );

        $this->assertSame(SendFailure::ACCOUNT, $failure->kind);
        $this->assertFalse($failure->retryable, 'Retrying a wrong password just locks the account faster.');
        $this->assertTrue($failure->releaseReservation);
    }

    public function test_a_421_is_treated_as_a_transient_account_problem(): void
    {
        $failure = $this->classifier->classify(
            $this->exception(
                'Expected response code "250" but got code "421", with message "421 4.7.0 Too many messages, slow down".',
                ['EHLO app.test', 'MAIL FROM:<me@example.test>']
            ),
            $this->account
        );

        $this->assertSame(SendFailure::ACCOUNT_TRANSIENT, $failure->kind);
        $this->assertTrue($failure->retryable);
        $this->assertStringContainsString('rate-limiting', $failure->summary);
    }

    public function test_a_connection_failure_is_an_account_problem(): void
    {
        $failure = $this->classifier->classify(
            new TransportException('Connection could not be established with host "smtp.example.test:587": Connection refused'),
            $this->account
        );

        $this->assertSame(SendFailure::ACCOUNT, $failure->kind);
        $this->assertTrue($failure->retryable);
        $this->assertStringContainsString('smtp.example.test', $failure->summary);
        $this->assertStringContainsString('587', $failure->summary);
    }

    public function test_a_tls_mismatch_explains_the_port(): void
    {
        $failure = $this->classifier->classify(
            new TransportException('Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed'),
            $this->account
        );

        $this->assertSame(SendFailure::ACCOUNT, $failure->kind);
        $this->assertStringContainsString('465', $failure->summary);
        $this->assertStringContainsString('587', $failure->summary);
    }

    public function test_an_unrecognised_error_is_retried_rather_than_guessed_at(): void
    {
        $failure = $this->classifier->classify(new RuntimeException('something odd happened'), $this->account);

        $this->assertSame(SendFailure::ACCOUNT_TRANSIENT, $failure->kind);
        $this->assertTrue($failure->retryable);
        $this->assertFalse($failure->suppressRecipient, 'Never suppress an address on a guess.');
    }

    // ------------------------------------------------------------- security

    public function test_the_credential_never_survives_into_the_stored_message(): void
    {
        $failure = $this->classifier->classify(
            $this->exception(
                'Expected response code "235" but got code "535", with message "535 rejected for mailer@example.test / top-secret-key"',
                ['AUTH PLAIN dXNlcgBwYXNz']
            ),
            $this->account
        );

        $this->assertStringNotContainsString('top-secret-key', $failure->message);
        $this->assertStringNotContainsString('mailer@example.test', $failure->message);
        $this->assertStringNotContainsString('dXNlcgBwYXNz', $failure->message,
            'The raw AUTH payload must be stripped too — it decodes to the credential.');
        $this->assertStringContainsString('[redacted]', $failure->message);
    }

    public function test_provider_specific_advice_is_given_for_auth_failures(): void
    {
        foreach ([
            'gmail' => 'App Password',
            'sendgrid' => 'apikey',
            'ses' => 'SES console',
            'microsoft' => 'SMTP AUTH',
        ] as $provider => $expected) {
            $account = SmtpAccount::factory()->make(['provider' => $provider]);

            $failure = $this->classifier->classify(
                $this->exception('Expected response code "235" but got code "535", with message "535 auth failed"'),
                $account
            );

            $this->assertStringContainsString(
                $expected,
                $failure->summary,
                "The {$provider} auth error should name the real cause."
            );
        }
    }

    // --------------------------------------------------------- stage parsing

    public function test_the_failing_stage_is_read_from_the_real_dialogue(): void
    {
        $this->assertSame('RCPT', $this->classifier->lastCommand(
            "> EHLO app\r\n< 250 ok\r\n> MAIL FROM:<a@b.c>\r\n< 250 ok\r\n> RCPT TO:<x@y.z>\r\n< 550 no\r\n"
        ));

        $this->assertSame('MAIL', $this->classifier->lastCommand(
            "> EHLO app\r\n< 250 ok\r\n> MAIL FROM:<a@b.c>\r\n< 550 no\r\n"
        ));

        $this->assertNull($this->classifier->lastCommand(null));
    }

    public function test_reply_codes_are_read_from_both_symfony_formats(): void
    {
        $this->assertSame(550, $this->classifier->replyCode('but got code "550", with message'));
        $this->assertSame(421, $this->classifier->replyCode('421 4.7.0 Too many connections'));
        $this->assertNull($this->classifier->replyCode('Connection refused'));
    }
}
