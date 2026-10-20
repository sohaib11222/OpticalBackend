<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\PasswordByteLength;
use App\Services\Audit;
use App\Services\EmailVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'optical:admin {email} {--name=}';

    protected $description = 'Provision a system administrator using a hidden password prompt (no public signup).';

    public function handle(): int
    {
        $data = ['email' => strtolower(trim($this->argument('email'))), 'name' => $this->option('name') ?: $this->ask('Name'), 'password' => $this->secret('Password (at least 12 characters, letters and numbers)')];
        $v = Validator::make($data, ['email' => 'required|email|unique:users,email', 'name' => 'required|string|max:150', 'password' => ['required', Password::min(12)->letters()->numbers(), new PasswordByteLength]]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $u = new User($data);
        $u->account_type = 'admin';
        $u->save();
        $u->sendEmailVerificationNotification();
        Audit::record('admin.provisioned', null, $u->id);
        $this->info(EmailVerification::required() ? 'Administrator created. A verification email has been queued.' : 'Administrator created. Email verification is disabled for development.');

        return self::SUCCESS;
    }
}
