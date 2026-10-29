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
