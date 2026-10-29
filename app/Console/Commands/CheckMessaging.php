<?php
namespace App\Console\Commands;
use App\Services\Communications\Provider;
use App\Services\Communications\OperationsHealth;
use Illuminate\Console\Command;
class CheckMessaging extends Command
{
    protected $signature = 'optical:check-messaging {--smtp : Test TLS and SMTP authentication without sending mail}';
    protected $description = 'Diagnose customer email configuration without printing credentials';
    public function handle(Provider $provider): int
    {
        $this->line('Configuration cache: '.(app()->configurationIsCached() ? 'cached; rebuild after .env edits' : 'not cached'));
        $issues = $provider->emailIssues();
        foreach ($issues as $issue) $this->error($issue);
        if (!$issues) $this->info('Aruba configuration: ready (credentials not yet verified)');
        $health = OperationsHealth::status();
        $this->line('Communications worker: '.($health['worker_active'] ? 'heartbeat OK' : 'no recent heartbeat'));
        $this->line('Scheduler: '.($health['scheduler_active'] ? 'heartbeat OK' : 'no recent heartbeat'));
        if ($issues) return self::FAILURE;
        if ($this->option('smtp')) {
            $transport = $provider->emailTransport();
            try { $transport->start(); $this->info('Aruba TLS connection and SMTP authentication: OK. No email sent.'); }
            catch (\Throwable) { $this->error('SMTP connection/authentication failed. Verify mailbox password, authorized SMTP access, outbound port, DNS and TLS certificate trust. No credentials or raw SMTP transcript are printed.'); return self::FAILURE; }
            finally { try { $transport->stop(); } catch (\Throwable) {} }
        }
        return self::SUCCESS;
    }
}
