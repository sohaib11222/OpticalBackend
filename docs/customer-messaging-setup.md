# Customer email and WhatsApp Web

Vista Express 360 sends customer **email through Aruba SMTP**. SMS, Twilio
callbacks, and automated WhatsApp delivery are retired. The WhatsApp buttons
in customer tables and customer details use official browser click-to-chat;
they open WhatsApp Web with the saved number and a greeting, and a staff member
chooses whether to send the message there.

## Aruba configuration

Set these values in the **Vista Express 360 Laravel backend** `.env`, never in
either React application and never in Git:

```dotenv
COMMUNICATIONS_EMAIL_ENABLED=true
COMMUNICATIONS_EMAIL_SYNC=true
COMMUNICATIONS_PUBLIC_URL=https://api.vistaexpressbusiness.com
ARUBA_SMTP_HOST=smtps.aruba.it
ARUBA_SMTP_PORT=465
ARUBA_SMTP_USERNAME=your-full-aruba-mailbox@example.com
ARUBA_SMTP_PASSWORD=your-aruba-mailbox-password
ARUBA_FROM_ADDRESS=your-full-aruba-mailbox@example.com
ARUBA_FROM_NAME="Vista Express 360"
```

Port `465` uses implicit TLS. Port `587` is supported only for an Aruba mailbox
configured for STARTTLS. The configured From address must be authorized for the
mailbox. Authentication/account-verification mail may use separate `MAIL_*`
settings; customer communication does not fall back to Gmail accidentally.

Apply configuration and validate it without sending a message:

```bash
cd /var/www/vistaexpressbusiness/backend
php artisan config:cache
sudo supervisorctl restart 'vistaexpressbusiness-worker:*'
sudo -u www-data php artisan optical:check-messaging --smtp
```

The diagnostic must report `Aruba TLS connection and SMTP authentication: OK`.
It does not disclose credentials or send a message.

## Sending behaviour

1. Select **Email** in Customer Communication, choose immediate or scheduled
   delivery, select recipients, compose, review, then send.
2. An immediate email is delivered synchronously after its database record has
   committed. It does not wait for a worker heartbeat.
3. A scheduled email remains `Scheduled` until its due time. The Laravel
   scheduler dispatches it to the `communications` worker.
4. `Sent` means Aruba accepted the SMTP submission. It does not claim that a
   recipient opened the message. Failed, temporary and ambiguous SMTP outcomes
   remain visible in message history and are never silently duplicated.
5. Bulk email creates one personalized record per customer; duplicate recipient
   addresses, invalid addresses, archived customers and explicit email opt-outs
   stop the bulk operation before any email is sent.

Existing explicit email opt-outs and unsubscribe links remain respected. New
manual or bulk email does not require a consent-recording step.

## Scheduler for future messages

Keep this single cron entry under the same OS user that runs PHP-FPM. Do not add
duplicates if an equivalent entry already exists:

```cron
* * * * * cd /var/www/vistaexpressbusiness/backend && /usr/bin/php artisan schedule:run >> /var/www/vistaexpressbusiness/backend/storage/logs/scheduler.log 2>&1
```

The Supervisor worker must include `communications`:

```ini
command=/usr/bin/php /var/www/vistaexpressbusiness/backend/artisan queue:work database --queue=uploads,integrations,communications,default --sleep=3 --tries=3 --timeout=300 --max-time=3600
```

Verify both parts with:

```bash
sudo -u www-data php artisan optical:check-messaging
sudo -u www-data php artisan optical:check-processing
```

## Controlled acceptance test

Send one immediate email to an authorized test inbox, then inspect its message
history, inbox and spam folder. Schedule a second message a few minutes ahead
and verify it sends after the cron run. Finally select two customers for a bulk
email and confirm each receives only their own personalized message. Test a
missing address and an explicit email opt-out; neither must send.

References: [Aruba mailbox settings](https://guide.aruba.it/hosting-e-domini/email/configurazione-posta/parametri-configurazione-posta) and [Symfony SMTP transport](https://symfony.com/doc/current/mailer.html).
