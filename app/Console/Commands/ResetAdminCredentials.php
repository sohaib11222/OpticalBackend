<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Rules\PasswordByteLength;
use App\Services\Audit;
use App\Services\Sessions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Rotates a privately provisioned platform-admin credential without putting a
 * password into a migration, seeder, shell history or source control.
 */
class ResetAdminCredentials extends Command
{
    protected $signature = 'optical:admin-reset
                            {email : The new platform-admin email address}
                            {--name= : The display name to keep on the account}
                            {--user= : Existing platform-admin user id to update}';

    protected $description = 'Replace a Vista Express 360 platform administrator email and password, then revoke existing sessions';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $admin = $this->targetAdmin();
        if (! $admin) {
            return self::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?: $admin->name ?: $this->ask('Admin display name')));
        $password = (string) $this->secret('New admin password (at least 12 characters, letters and numbers)');

        $validator = Validator::make(
            compact('email', 'name', 'password'),
            [
                'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email,'.$admin->id],
                'name' => ['required', 'string', 'max:150'],
                'password' => ['required', Password::min(12)->letters()->numbers(), new PasswordByteLength],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($admin, $email, $name, $password) {
            $admin->forceFill([
                'email' => $email,
                'name' => $name,
                'password' => $password,
                'account_type' => 'admin',
                'status' => 'active',
                // Administrators are provisioned privately and do not require
                // public mailbox verification to use the platform panel.
                'email_verified_at' => $admin->email_verified_at ?? now(),
            ])->save();
            Sessions::revoke($admin);
            Audit::record('admin.credentials_rotated', null, $admin->id, 'user', $admin->id);
        });

        $this->info('Platform administrator credentials updated. All existing sessions for this account were revoked.');
        $this->line('Sign in to the Vista Express 360 Admin Panel with the new email and password.');

        return self::SUCCESS;
    }

    private function targetAdmin(): ?User
    {
        $id = $this->option('user');
        if ($id !== null) {
            $admin = User::where('account_type', 'admin')->find($id);
            if (! $admin) {
                $this->error("No platform administrator exists with user id {$id}.");
            }

            return $admin;
        }

        $admins = User::where('account_type', 'admin')->orderBy('id')->get();
        if ($admins->isEmpty()) {
            // Reuse the same private path as optical:admin. The newly created
            // row is immediately filled below with the supplied credentials.
            return new User(['account_type' => 'admin', 'status' => 'active']);
        }
        if ($admins->count() > 1) {
            $this->warn('Multiple platform administrators exist; rotating the lowest user id. Use --user=<id> to choose another.');
        }

        return $admins->first();
    }
}
