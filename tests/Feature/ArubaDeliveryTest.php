<?php
namespace Tests\Feature;

use App\Models\CustomerMessage;
use App\Models\Shop;
use App\Services\Communications\Provider;
use App\Services\Communications\DeliveryFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

class ArubaDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function configureEmail(): void
    {
        config(['communications.enabled' => false, 'communications.email_enabled' => true,
            'communications.public_url' => 'https://crm.example.test',
            'communications.aruba' => ['host' => 'smtps.aruba.it', 'port' => 465,
                'username' => 'sender@example.test', 'password' => 'test-only-secret',
                'from_address' => 'sender@example.test', 'from_name' => 'Vista Express 360']]);
    }

    public function test_email_can_be_enabled_independently_of_unconfigured_sms_and_whatsapp(): void
    {
        $this->configureEmail();
        $p = new Provider;
        $this->assertTrue($p->ready('email'));
        $this->assertFalse($p->ready('sms'));
        $this->assertFalse($p->ready('whatsapp'));
        $this->assertSame([], $p->emailIssues());
        config(['communications.email_enabled' => false, 'communications.enabled' => true]);
        $this->assertFalse($p->ready('email'));
        config(['communications.email_enabled' => null]);
        $this->assertTrue($p->ready('email'));
    }

    public function test_readiness_explains_configuration_problems_without_exposing_values(): void
    {
        $this->configureEmail();
        config(['communications.aruba.port' => 25, 'communications.aruba.from_address' => 'invalid',
            'communications.public_url' => 'http://localhost', 'communications.aruba.username' => null]);
        $issues = implode(' ', (new Provider)->emailIssues());
        foreach (['ARUBA_SMTP_PORT', 'ARUBA_FROM_ADDRESS', 'COMMUNICATIONS_PUBLIC_URL', 'ARUBA_SMTP_USERNAME'] as $key) $this->assertStringContainsString($key, $issues);
        $this->assertStringNotContainsString('test-only-secret', $issues);
        $this->assertFalse((new Provider)->ready('email'));
    }

    public function test_smtp_diagnostic_does_not_send_or_connect_when_configuration_is_invalid(): void
    {
        $this->configureEmail();
        config(['communications.aruba.password' => null]);
        $this->artisan('optical:check-messaging', ['--smtp' => true])
            ->expectsOutput('Missing ARUBA_SMTP_PASSWORD.')
            ->assertFailed();
    }

    public function test_environment_fallback_uses_only_aruba_mail_and_preserves_explicit_overrides(): void
    {
        $env = ['COMMUNICATIONS_ENABLED' => 'false', 'COMMUNICATIONS_EMAIL_ENABLED' => 'true',
            'MAIL_USERNAME' => 'fallback@example.test', 'MAIL_PASSWORD' => 'fallback-test-password',
            'MAIL_FROM_ADDRESS' => 'fallback@example.test', 'MAIL_PORT' => '465',
            'ARUBA_SMTP_HOST' => 'smtps.aruba.it', 'ARUBA_SMTP_PORT' => '465',
            'ARUBA_SMTP_USERNAME' => '', 'ARUBA_SMTP_PASSWORD' => '', 'ARUBA_FROM_ADDRESS' => ''];
        $read = function (array $values): array {
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r',
                'require "vendor/autoload.php"; $c=require "config/communications.php"; echo json_encode([$c["email_enabled"],$c["aruba"]["username"],$c["aruba"]["password"],$c["aruba"]["from_address"]]);'], base_path(), $values);
            $process->mustRun();
            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        };
        $this->assertSame([true, 'fallback@example.test', 'fallback-test-password', 'fallback@example.test'], $read([...$env, 'MAIL_HOST' => ' SMTPS.ARUBA.IT ']));
        $this->assertSame([true, null, null, null], $read([...$env, 'MAIL_HOST' => 'smtp.gmail.com']));
        $this->assertSame([true, 'explicit@example.test', 'explicit-test-password', 'explicit@example.test'], $read([...$env, 'MAIL_HOST' => 'smtps.aruba.it', 'ARUBA_SMTP_USERNAME' => 'explicit@example.test', 'ARUBA_SMTP_PASSWORD' => 'explicit-test-password', 'ARUBA_FROM_ADDRESS' => 'explicit@example.test']));
    }

    public function test_email_submission_keeps_recipient_private_and_does_not_claim_delivery(): void
    {
        config(['communications.enabled'=>true, 'communications.public_url'=>'https://crm.example.test', 'communications.aruba.host'=>'smtps.aruba.it', 'communications.aruba.port'=>465, 'communications.aruba.username'=>'sender@example.test', 'communications.aruba.password'=>'test-only', 'communications.aruba.from_address'=>'sender@example.test', 'communications.aruba.from_name'=>'Vista Express 360']);
        $shop=Shop::factory()->create();
        $message=new CustomerMessage(['channel'=>'email','recipient'=>'customer@example.test','subject'=>'Your next visit','body'=>'Hello Customer','public_id'=>'6a2651aa-a88a-4e5d-8c2d-638ccf1be220']);
        $message->shop_id=$shop->id;
        $provider=new class extends Provider {
            public ?Email $email=null;
            public bool $fail=false;
            protected function deliverEmail(Email $email, EsmtpTransport $transport): void {
                $this->email=$email;
                if($this->fail) throw new \RuntimeException('SMTP connection failed');
            }
        };
        $this->assertSame('accepted',$provider->send($message)['status']);
        $this->assertCount(1,$provider->email->getTo());
        $this->assertSame('customer@example.test',$provider->email->getTo()[0]->getAddress());
        $this->assertSame('sender@example.test',$provider->email->getFrom()[0]->getAddress());
        $this->assertStringContainsString('/communications/preferences/',$provider->email->getTextBody());
        $this->assertSame('Your next visit',$provider->email->getSubject());
        $provider->fail=true;
        try { $provider->send($message); $this->fail('An ambiguous SMTP failure must not be treated as delivered'); }
        catch(DeliveryFailure $e) { $this->assertSame('uncertain',$e->state); }
    }
}
