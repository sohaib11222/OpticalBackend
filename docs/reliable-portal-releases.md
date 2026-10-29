# Reliable portal releases and scheduler recovery

## Findings verified on 29 October 2026

- Shop public HTML references `index-C-CRYFrq.js`, Last-Modified October 26. That bundle lacks `wizard.channel`, `ops.bulkEmail`, and customer composer actions. Server checkout supplied by the owner is `6680623`, the current local frontend commit. Source update did not reach the served build.
- Admin public bundle `index-laCUYZ7g.js` has SHA256 `ea7ffdf1161ef6154dff00615bc10b19943b9a758fc9340d6a1e799759c0f6a8`, identical to the local build. It includes the wizard, bulk email and customer actions. Do not diagnose Admin as an old build solely from missing buttons.
- Both public HTML responses lack Cache-Control. The known Nginx document roots are `frontend/www` and `admin/www`; Vite produces `dist`. PM2 is not involved in serving these two static portals in the supplied configuration.
- Original Vista `/css/optical-seller-access.css` and `/images/optical-seller-access.webp` return 404. Both assets exist but are untracked in the local original Vista Git repository. The React development proxies point to the live 360 API, so seller authentication leaves localhost and uses the original live Vista backend. Editing local Vista assets does not change that remote page.
- Owner confirms Aruba TLS/authentication and worker heartbeat succeed. There is no www-data crontab or matching system cron entry. Laravel lists the tasks, but that does not invoke them. Nano reported “No modification made”. The application warning correctly reflects a missing completed operations tick.
- Server Git drift: frontend generated tsbuildinfo, Admin six extra commits plus tracked www/index.html and untracked `on`, backend edited bootstrap/app.php and config/cors.php. Preserve these backend changes until reviewed; do not reset or clean repositories blindly.

## Scheduler (one-time)

First check existing timers and cron to avoid duplicates. When absent, install this dedicated cron file (the user column belongs in /etc/cron.d):

```bash
printf '%s\n' '* * * * * www-data cd /var/www/vistaexpressbusiness/backend && /usr/bin/php artisan schedule:run >> /var/www/vistaexpressbusiness/backend/storage/logs/scheduler.log 2>&1' | sudo tee /etc/cron.d/vistaexpressbusiness-scheduler
sudo chmod 644 /etc/cron.d/vistaexpressbusiness-scheduler
```

After two minutes, inspect `storage/logs/scheduler.log` and run `sudo -u www-data php artisan optical:check-messaging` from the backend. Cron must be active, and www-data must be able to append the log. If no tick appears, read the actual scheduler error; do not clear locks or suppress the warning without diagnosis. Enabling this processes due messages/follow-ups. Send-now only needs the worker. Laravel reference: https://laravel.com/docs/12.x/scheduling

## Prepare source repositories

In the local original Vista repository explicitly add these required release assets to the intended commit, along with the controller/view changes:

```bash
git add backend/public/css/optical-seller-access.css backend/public/images/optical-seller-access.webp
```

Commit/push those files using your normal workflow. Then pull original Vista on its server and run `php artisan view:clear` in `/var/www/vistaexpress/optyamazone/backend`. Check both asset URLs return 200 with CSS/image content. Rebuilding React cannot fix missing original Vista assets.

Commit/push the updated backend deployment scripts, frontend ignore rule, and all changed shared contracts. Ensure `contracts` is an independent Git checkout at `/var/www/vistaexpressbusiness/contracts`, with `operations/MessageWizard.tsx` at that path. Avoid keeping the only updated shared copy outside the repository you push.

## One-time server Git reconciliation

Back up each dirty checkout and inspect `git diff` before updating it. Generated files are not source: `.gitignore` does not untrack files already committed. Remove generated `www/` and `*.tsbuildinfo` from tracking in the appropriate source history with `git rm --cached` (files remain on disk), and commit that cleanup. Reconcile meaningful server-only changes into the development repository, then align branches through a reviewed merge or fresh checkout. Preserve server environment files. Do not push server merge commits or use reset --hard just to make errors disappear. Backend CORS/bootstrap differences require review.

The release command intentionally refuses dirty checkouts, wrong branches, and server-only commits. It never deletes source changes or adjusts other applications. The currently diverged Admin must be reconciled before using it.

## Every future frontend/Admin release

Pull the backend deployment tooling first after reviewing its server edits. Then run separately:

```bash
cd /var/www/vistaexpressbusiness/backend
node deploy/release-portal.mjs frontend https://vistaexpressbusiness.com
node deploy/release-portal.mjs admin https://admin.vistaexpressbusiness.com
```

Each command checks independent main-branch checkouts, fetches and fast-forwards portal/contracts, installs locked dependencies, builds with root base `/`, records commit IDs in `release.json`, validates/publishes `dist` into `www` with a backup, and compares every public file against the build using SHA256. Ordinary login/deep-link HTML is checked too. A verification failure after publication reports failure; use the recorded backup if rollback is needed. The command does not claim authenticated business-flow acceptance.

Read-only diagnosis of an existing build:

```bash
node deploy/release-portal.mjs frontend https://vistaexpressbusiness.com --verify-only
node deploy/release-portal.mjs admin https://admin.vistaexpressbusiness.com --verify-only
```

A different index means wrong document root or stale cache; a missing/different asset means incomplete publication/cache. Check the existing Nginx vhost before changing it. Keep current API/Sanctum/PHP locations and /pulse-ui redirects. In each existing `location /` add `add_header Cache-Control "no-cache" always;`; give an explicit index.html location the same document root and header if needed. `release.json` should also revalidate. Run `sudo nginx -t` before reload. Avoid year-long cache headers for mutable filenames such as auth images. Only hashed `/assets/` files can safely have immutable caching. No CDN was established from the supplied evidence; if one exists, purge mutable HTML/assets and check cache rules after matching the origin.

## Authenticated acceptance still required

- Refresh Shop and Admin separately; verify public release.json and latest HTML. An already-open tab keeps its loaded JavaScript until refreshed.
- In Admin select a shop workspace, then an active saved customer. SMS/email/WhatsApp actions require both communications.read and communications.send. Bulk actions need customers selected; missing contact addresses disable the relevant button. Unsaved customer forms cannot send to a record that does not exist. Do not remove permissions to make buttons appear.
- In Shop verify the same customer actions, wizard steps, immediate email, and one scheduled email after scheduler recovery.
- In Vista sign-in inspect the address bar: original Vista serves that page. Confirm CSS/image 200, EN/IT switching and successful callback. For fully local verification, use isolated local backend configuration; do not repoint the production integration.
- SMTP authentication is verified by the supplied production diagnostic. Inbox receipt and authenticated live workflows remain pending; no customer messages were sent during this deployment investigation.

Automated deployment tests: four passing tests cover safe publication, wrong-base/stale-build rejection, and public-byte verification/rejection separately for frontend and Admin.
