# Restore production upload processing and Vista synchronization

## Confirmed deployment causes — 25 October 2026

The supplied Supervisor configuration runs `queue:work database` without named
queues. It consumes `default` only. Scans, image processing and customer import
jobs use `uploads`; Vista snapshots/publications use `integrations`; customer
messages use `communications`. ClamAV was initially absent. After installation,
the supplied service output shows it is still inactive because the initial daily
virus signature database is missing. A running default worker therefore cannot
process uploads or Vista data, and scanning also requires the signature download
and the correct local socket address.

No security scan may be bypassed. Existing files stay private and inaccessible
until clean. Vista records remain scoped to the seller store authorized by the
grant; standalone CRM data is separate. Marketplace customers are buyers with
orders belonging to that seller's store, not all users of Vista.

## 1. Install the scanner on Ubuntu

```sh
sudo apt-get update
sudo apt-get install clamav clamav-daemon
sudo systemctl stop clamav-freshclam
sudo freshclam
sudo systemctl enable --now clamav-freshclam
sudo systemctl enable --now clamav-daemon
sudo systemctl status clamav-daemon --no-pager -l
sudo grep '^LocalSocket' /etc/clamav/clamd.conf
```

The signature download must finish successfully before starting the scanner.
If startup fails, inspect `sudo journalctl -u clamav-daemon -n 40 --no-pager`.
Do not run a second freshclam process if an update is already in progress.
If manual freshclam reports `Failed to lock the log file`, stop
`clamav-freshclam` first, then run `sudo freshclam` once. If the lock persists,
inspect `pgrep -a freshclam` and `sudo fuser /var/log/clamav/freshclam.log`
instead of deleting the log or forcibly killing an unidentified process.
Restart the updater service after the manual run, including after a failed
download, so scheduled updates are not left disabled.
An unmet `ConditionPathExistsGlob=/var/lib/clamav/daily.{c[vl]d,inc}` means the
definitions are still missing. If freshclam reports a download or rate-limit
error, resolve that specific error before proceeding; reinstalling the daemon
or restarting the app cannot replace the signature database.

Use the actual LocalSocket path from that output in the **360** `.env`. For the
standard Ubuntu socket:

```dotenv
FILE_SCANNER=clamav
CLAMD_ADDRESS=unix:///var/run/clamav/clamd.ctl
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=360
```

Do not retain the TCP address unless clamd explicitly listens on that TCP port.
Keep the socket local. If access is denied, add only the app worker user to the
socket's group (normally `sudo usermod -aG clamav www-data`), then restart the app
workers. Do not expose ClamAV to the internet or chmod storage to 777.

## 2. Deploy code and fix only the 360 worker

After pulling the backend changes:

```sh
cd /var/www/vistaexpressbusiness/backend
sudo cp /etc/supervisor/conf.d/vistaexpressbusiness-worker.conf /etc/supervisor/conf.d/vistaexpressbusiness-worker.conf.backup
sudo cp deploy/vistaexpressbusiness-worker.conf /etc/supervisor/conf.d/vistaexpressbusiness-worker.conf
php artisan config:cache
sudo supervisorctl reread
sudo supervisorctl update vistaexpressbusiness-worker
sudo supervisorctl restart 'vistaexpressbusiness-worker:*'
sudo supervisorctl status
```

The supplied config runs two consumers of uploads, integrations, communications,
default. The 300-second worker timeout / 360-second retry_after allow the existing
240-second import job. Other projects' workers must remain untouched. For larger
deployments split queues into dedicated pools to avoid upload load delaying sync.
`--queue=...` and `--timeout=300` are arguments within Supervisor's `command=`
line, not standalone shell commands. Each consumer has its own rotating log.

Verify PHP CLI and PHP-FPM have GD, fileinfo and ZIP enabled; install the matching
PHP-version packages if absent. Both must access the same private storage root.
Run `sudo -u www-data php artisan optical:check-processing` from the backend.
This writes/deletes a unique harmless storage probe, requests a real scanner
verdict and checks worker heartbeats without exposing customer data or tokens.
Run it after workers have had a few seconds to start. A nonzero result means a
dependency still needs fixing. If storage fails, fix ownership/permissions of
this application's `storage` and `bootstrap/cache` for its deployment/PHP users.

## 3. Scheduler and recover existing work

Check `sudo crontab -u www-data -l`. Add this once with
`sudo crontab -u www-data -e` if not already present (preserve existing entries):

```cron
* * * * * cd /var/www/vistaexpressbusiness/backend && /usr/bin/php artisan schedule:run >> /var/www/vistaexpressbusiness/backend/storage/logs/scheduler.log 2>&1
```

An active cron service alone does not mean this application's schedule is registered.
Once diagnostics succeed:

```sh
cd /var/www/vistaexpressbusiness/backend
sudo -u www-data php artisan optical:retry-file-scans
sudo -u www-data php artisan optical:reconcile-vista
php artisan queue:failed
```

Already-pending jobs are consumed automatically. Scan recovery covers old pending
and failed files. Do not run `queue:retry all`: unrelated failed emails may be sent.
Use the import's Retry action for an interrupted import; its version and per-row
keys preserve duplicate prevention. For Vista, inspect the connection's store ID,
status and last error; do not create another shop or replace the grant by hand.

## 4. Acceptance

1. Upload a small UTF-8 CSV: pending -> clean -> **Continue to column mapping**
   (customer columns, not product mapping). Map names/email, preview, resolve
   duplicates explicitly, commit and confirm the customer list.
2. Upload a product JPEG/PNG: pending -> clean; thumbnail/primary image becomes
   visible. Reload to verify it is served from the private display endpoint.
3. Upload a customer photo and a PDF document: clean status and authorized access;
   verify another shop cannot access them. Invalid formats/oversize files must fail.
4. In a staging environment, stop the scanner and upload a fixture: it must remain
   unavailable with failed/retry feedback, never falsely clean. Restore the scanner
   and retry; the CSV mapping button must enable without needing a page reload.
5. Open Vista: verify the displayed store ID/name against the Seller Panel. Wait
   for the initial snapshot and compare product/order/customer counts and values.
   A store without buyer orders may legitimately have no Vista customers.
6. Update a controlled test listing in Vista; allow the scheduler/worker cycle,
   then confirm 360 refreshes its source snapshot. Verify a second shop cannot see it.

Local tests use synthetic files and isolated databases. Actual server repair,
scanner diagnostics and authenticated seller comparison require these steps on
the deployed server; they cannot be certified from the local checkout alone.
