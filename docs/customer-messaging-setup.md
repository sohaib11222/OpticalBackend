# Customer messaging and seller authorization — 28 October 2026

## What changed
- Customer tables and saved-customer details in Shop and scoped Admin workspaces offer SMS, WhatsApp and Email actions. WhatsApp opens the existing approved-template composer; no external chat links or bulk WhatsApp were added.
- Select up to 100 active customers across list pages, choose Bulk SMS or Bulk email, enter content and an optional delay, then review recipients. Resolve missing consent/contact details or duplicate destinations before confirming. No recipient is queued until the entire selection passes validation.
- Every recipient gets a separate personalized message and delivery history. Repeating the same submission is idempotent. Consent, permissions, account status and tenant access are rechecked when messages are sent.
- Twilio SMS accepts either a configured Messaging Service or an international sender number. Aruba email uses authenticated TLS SMTP. Provider settings remain platform-wide; shops never receive credentials.
- Vista seller authorization now receives the selected language from 360, offers English/Italian navigation, and has a responsive editorial layout. Switching language preserves PKCE/state and the original expiry. Existing seller sessions remain untouched.

## Configuration (360 backend only)
Keep `COMMUNICATIONS_ENABLED=false` until credentials and controlled tests are ready. Add values on the server, never commit them:

```dotenv
COMMUNICATIONS_ENABLED=false
COMMUNICATIONS_PUBLIC_URL=https://api.vistaexpressbusiness.com
COMMUNICATIONS_PER_MINUTE=30
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_SMS_FROM=
TWILIO_MESSAGING_SERVICE_SID=
ARUBA_SMTP_HOST=smtps.aruba.it
ARUBA_SMTP_PORT=465
ARUBA_SMTP_USERNAME=
ARUBA_SMTP_PASSWORD=
ARUBA_FROM_ADDRESS=
ARUBA_FROM_NAME="Vista Express 360"
```

Use the full Aruba mailbox as username and an authorized From address. Port 465 uses implicit TLS; port 587 requires STARTTLS and should only be selected when supported by the mailbox service. Authentication emails retain their existing MAIL_* configuration. Leave existing WhatsApp credentials/templates unchanged. SMS From must be E.164, e.g. +countrycode…; alternatively configure a Twilio Messaging Service with a suitable SMS sender.

The application supplies a per-message signed-validation callback URL to Twilio. The HTTPS API host must allow Twilio POST requests to `/api/v1/communications/callback/{public_id}`. Existing inbound opt-out and suppression handling must remain configured. Do not weaken callback signature verification.

Workers must consume `communications` as well as the existing queues. Configure the existing Laravel scheduler once (check system cron and other users before adding another entry):

```cron
* * * * * cd /var/www/vistaexpressbusiness/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Run this as the application OS user. After changing environment configuration, run `php artisan config:cache` and restart only the 360 Supervisor worker group. Check `php artisan optical:check-processing`.

## Deployment
1. Back up the database using the normal deployment process. Pull the 360 backend, frontend, Admin and shared contracts repositories.
2. In the 360 backend run `php artisan migrate --force` (adds communication_batches), then refresh configuration and restart 360 workers.
3. Build both portals and run the existing `backend/deploy/publish-static.mjs` release helper for each portal; Nginx serves `www`, not `dist`. Preserve the production root base `/`.
4. Pull the original Vista marketplace backend too: the seller authorization controller/view, CSS and image live there. Run `php artisan view:clear` in that backend. No marketplace database migration is introduced by this change.
5. Set credentials, enable communications, refresh config and restart workers only when ready for a controlled delivery test. This development change does not send real messages.

## Acceptance checklist
- Shop and scoped Admin: select two customers with different valid destinations and recorded service consent. Bulk SMS: review two eligible rows, confirm, verify two separate personalized history entries.
- Repeat for email; subject is required. Each recipient must see only their own address.
- Missing consent, invalid destination, archived customer or duplicate destination: review explains the problem; nothing is sent. Remove the ineligible recipient or correct the customer/consent then review again.
- Retry the same API creation key: same batch/messages returned. Reuse with changed content: conflict. A foreign-shop ID must return unavailable; lacking send permission must deny access.
- Disable credentials: messages remain visibly blocked; there must be no false Delivered status. Restore credentials and use the existing Retry action for eligible blocked messages.
- Twilio: verify accepted -> delivered/failed status and event history. Invalid callback signatures must be rejected. Throttling schedules a safe retry; ambiguous submissions require reconciliation instead of automatic duplicate sends.
- Email: Accepted means Aruba accepted the SMTP submission, not inbox delivery. Check the recipient inbox/spam folder in the controlled live test. SMTP uncertainty requires reconciliation. Confirm unsubscribe updates suppression before another send.
- Withdraw consent after scheduling: dispatch must block the message. Confirm marketing consent cannot substitute for service consent or vice versa.
- Customer row and detail WhatsApp actions open the existing template composer with that customer selected; existing WhatsApp sending behavior remains unchanged.
- Set 360 to Italian, start Vista sign-in, verify Italian provider page. Toggle English and Italian, submit invalid credentials, then successfully authorize a test seller. Confirm redirect/state and existing seller sessions work. Check desktop and mobile layout.

## Provider references
[Twilio Message resource](https://www.twilio.com/docs/messaging/api/message-resource) documents sender/service selection and status callbacks. [Aruba mailbox parameters](https://guide.aruba.it/hosting-e-domini/email/configurazione-posta/parametri-configurazione-posta) documents authenticated secure SMTP settings.

## Verification boundary
Automated tests use isolated databases and simulated providers. Real SMS delivery, Aruba inbox delivery and live-server deployment require configured credentials and a controlled recipient; they cannot be claimed from local tests.

Local verification completed 29 October 2026: 20 messaging backend tests (303 assertions), 2 Vista authorization tests (20 assertions), Shop/Admin bulk-message browser flows, and Italian seller authorization through to the integration page. Both final production builds pass. Desktop/mobile authorization screenshots were visually reviewed. No real provider deliveries were performed.
