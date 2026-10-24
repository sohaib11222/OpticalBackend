<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetAccountPassword;
use App\Notifications\VerifyAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function gmail(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => 'smtp.gmail.com',
            'mail.mailers.smtp.scheme' => 'smtp',
            'mail.mailers.smtp.port' => 587,
            'mail.mailers.smtp.username' => 'synthetic@gmail.com',
            'mail.mailers.smtp.password' => 'synthetic-secret',
            'mail.mailers.smtp.require_tls' => true,
            'mail.mailers.smtp.timeout' => 30,
            'mail.from.address' => 'synthetic@gmail.com',
        ]);
    }

    public function test_check_validates_configuration_without_connecting_or_exposing_secrets(): void
    {
        $this->gmail();
        Mail::shouldReceive('mailer')->never();
        $this->artisan('optical:mail-check')
            ->expectsOutputToContain('No network connection or email send was attempted')
            ->doesntExpectOutputToContain('synthetic-secret')
            ->doesntExpectOutputToContain('synthetic@gmail.com')
            ->assertSuccessful();
    }

    public function test_missing_credentials_and_unsafe_gmail_settings_fail_before_sending(): void
    {
        Mail::shouldReceive('mailer')->never();
        foreach ([
            ['mail.mailers.smtp.password', '', 'Set MAIL_PASSWORD'],
            ['mail.mailers.smtp.username', '', 'Set MAIL_USERNAME'],
            ['mail.from.address', '', 'Set a valid MAIL_FROM_ADDRESS'],
            ['mail.from.address', 'different@example.test', 'Use the same testing Gmail address'],
            ['mail.mailers.smtp.scheme', 'tls', 'Gmail requires MAIL_SCHEME'],
            ['mail.mailers.smtp.require_tls', false, 'Set MAIL_REQUIRE_TLS=true'],
            ['mail.mailers.smtp.password', 'abcd efgh ijkl mnop', 'Remove display spaces'],
            ['mail.mailers.smtp.url', 'smtp://hidden:secret@example.test', 'Remove MAIL_URL'],
            ['mail.default', 'log', 'Set MAIL_MAILER=smtp'],
        ] as [$key, $value, $message]) {
            $this->gmail();
            config([$key => $value]);
            $this->artisan('optical:mail-check', ['--send-to' => 'tester@example.test'])
                ->expectsOutputToContain($message)->assertFailed();
        }
        $this->gmail();
        $this->artisan('optical:mail-check', ['--send-to' => 'invalid'])
            ->expectsOutputToContain('Supply a valid --send-to')->assertFailed();
    }

    public function test_installed_smtp_transport_enforces_tls_certificate_defaults_and_bounded_timeout(): void
    {
        $this->gmail();
        $transport = Mail::mailer('smtp')->getSymfonyTransport();
        $this->assertTrue($transport->isAutoTls());
        $this->assertTrue($transport->isTlsRequired());
        $this->assertSame('smtp.gmail.com', $transport->getStream()->getHost());
        $this->assertEquals(587, $transport->getStream()->getPort());
        $this->assertEquals(30, $transport->getStream()->getTimeout());
        $this->assertNotSame(false, $transport->getStream()->getStreamOptions()['ssl']['verify_peer'] ?? true);
        $this->assertNotSame(false, $transport->getStream()->getStreamOptions()['ssl']['verify_peer_name'] ?? true);
    }

    public function test_explicit_diagnostic_send_reports_acceptance_and_does_not_verify_accounts(): void
    {
        $this->gmail();
        $user = User::factory()->unverified()->create();
        $mailer = Mockery::mock(Mailer::class);
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('raw')->once()->withArgs(function ($body, $callback) {
            $message = new Message(new Email);
            $callback($message);
            $this->assertSame('tester@example.test', $message->getSymfonyMessage()->getTo()[0]->getAddress());
            $this->assertStringContainsString('does not verify an account', $body);

            return true;
        });
        $this->artisan('optical:mail-check', ['--send-to' => 'tester@example.test'])
            ->expectsOutputToContain('SMTP accepted the diagnostic message')->assertSuccessful();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_transport_failure_is_redacted_and_returns_nonzero(): void
    {
        $this->gmail();
        $mailer = Mockery::mock(Mailer::class);
        Mail::shouldReceive('mailer')->once()->with('smtp')->andReturn($mailer);
        $mailer->shouldReceive('raw')->once()->andThrow(new RuntimeException('sensitive SMTP transcript synthetic-secret'));
        $this->artisan('optical:mail-check', ['--send-to' => 'tester@example.test'])
            ->expectsOutputToContain('SMTP send failed')
            ->doesntExpectOutputToContain('sensitive SMTP transcript')
            ->doesntExpectOutputToContain('synthetic-secret')->assertFailed();
    }

    public function test_database_worker_delivers_both_account_notifications_without_gmail_credentials(): void
    {
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        foreach (['shop', 'admin'] as $kind) {
            $user = User::factory()->unverified()->create(['account_type' => $kind]);
            $user->sendEmailVerificationNotification();
            $user->sendPasswordResetNotification('synthetic-reset-token');
        }
        $this->assertSame(3, DB::table('jobs')->count()); // Shop verification + both password resets.
        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1])
            ->assertSuccessful();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertCount(3, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_queued_verification_and_reset_render_through_mail_for_both_panels(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'sender@example.test']);
        Queue::fake();
        foreach (['shop', 'admin'] as $kind) {
            $user = User::factory()->unverified()->create(['account_type' => $kind]);
            $user->sendEmailVerificationNotification();
            $user->sendPasswordResetNotification('synthetic-reset-token');
        }
        Queue::assertPushed(SendQueuedNotifications::class, 3);
        $jobs = Queue::pushed(SendQueuedNotifications::class);
        foreach ($jobs as $entry) {
            // Exercise the real queued notification handler, renderer and mail channel; only transport is in memory.
            $job = unserialize(serialize($entry));
            $this->assertTrue($job->afterCommit);
            $this->assertSame(3, $job->tries);
            $user = $job->notifiables->first();
            $notification = $job->notification;
            $url = $notification->toMail($user)->actionUrl;
            $base = config($user->account_type === 'admin' ? 'optical.admin_url' : 'optical.shop_url');
            $this->assertStringStartsWith($base.'/auth/', $url);
            if ($notification instanceof VerifyAccount) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->getJson($query['url'])->assertOk();
                $this->assertTrue($user->fresh()->hasVerifiedEmail());
            } else {
                $this->assertInstanceOf(ResetAccountPassword::class, $notification);
                $this->assertStringContainsString('/auth/reset-password?', $url);
            }
            $job->handle(app(ChannelManager::class));
        }
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(3, $messages);
        foreach ($messages as $sent) {
            $email = $sent->getOriginalMessage();
            $this->assertNotEmpty($email->getHtmlBody());
            $this->assertNotEmpty($email->getTextBody());
            $this->assertSame('sender@example.test', $email->getFrom()[0]->getAddress());
        }
    }
}
