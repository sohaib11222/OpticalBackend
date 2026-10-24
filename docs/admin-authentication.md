# Platform Admin access without email verification

Changed on 24 October 2026 at the owner's request.

Platform Admin accounts (`account_type=admin`) no longer require email verification in any environment. This applies to existing accounts, newly provisioned accounts and accounts after an email-address change. The API reports `email_verification_required=false`, which the existing Admin UI uses to show authorized navigation instead of the verification gate. The actual `email_verified_at` value is not falsified.

The `optical:admin` command no longer sends verification mail. The Admin resend endpoint returns a clear not-required response without sending mail. Shop owner/employee verification rules, password checks, password recovery, account status, session revocation, role permissions and tenant isolation remain unchanged.

## Deploy

Push the matching backend changes to its repository, then pull them using the existing live deployment process. Runtime files:

- `app/Services/EmailVerification.php`
- `app/Models/User.php`
- `app/Http/Resources/SessionResource.php`
- `app/Http/Controllers/Api/V1/AuthController.php`
- `app/Console/Commands/CreateAdmin.php`

From the deployed backend directory:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

Reload PHP-FPM through the existing deployment procedure if opcode caching is configured not to detect changed files. No database migration, seeding, email-verification timestamp update or Admin frontend rebuild is required for this change. Refresh the Admin page or sign out/in after deploying.

Create a new Admin only when needed:

```bash
php artisan optical:admin your-email@example.com --name="Platform Admin"
```

Enter its password at the hidden prompt. An existing Admin can use its existing credentials immediately after deployment; rerunning this command with an existing email is not a password reset.

## Verification

Automated checks cover unverified Admin access under production/staging settings, false verification-required state without changing verification timestamps, no Admin verification notifications, CLI-created Admin login, unchanged shop verification and denied shop access to platform routes. Mail regression checks retain Shop verification and password-reset delivery for both account types. Tests use the enforced in-memory SQLite configuration, not the live database.
