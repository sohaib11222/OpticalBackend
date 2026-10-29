# Customer email, message composer and Vista publishing pages

## Diagnosis and configuration

Customer messages use `config/communications.php`, not Laravel's authentication-mail transport. Credentials alone do not enable delivery. Previously, `COMMUNICATIONS_ENABLED=false` disabled email even when Aruba credentials were present; `MAIL_*` values were never used by this module. A running queue worker also retains its old configuration after `.env` edits. These are code-level findings; the current production configuration still requires the server diagnostic below.

The email channel now supports `COMMUNICATIONS_EMAIL_ENABLED=true` independently of the global Twilio/WhatsApp switch. An explicit false disables email; omitting the override preserves the global setting. Aruba-specific credentials take precedence. Standard `MAIL_*` credentials are accepted as fallback **only** when `MAIL_HOST` explicitly names `smtps.aruba.it` or `smtp.aruba.it`. Gmail settings are never used as an accidental customer sender. Readiness reports each missing/invalid setting without displaying its value.

Configure in the **360 backend**, not either React project:

```dotenv
COMMUNICATIONS_EMAIL_ENABLED=true
COMMUNICATIONS_PUBLIC_URL=https://api.vistaexpressbusiness.com
ARUBA_SMTP_HOST=smtps.aruba.it
ARUBA_SMTP_PORT=465
ARUBA_SMTP_USERNAME=your-full-mailbox-address
ARUBA_SMTP_PASSWORD=your-mailbox-password
ARUBA_FROM_ADDRESS=your-authorized-mailbox-address
ARUBA_FROM_NAME="Vista Express 360"
```

Keep the existing SMS/WhatsApp settings unchanged. This update requires no new migration. Refresh configuration and restart only the 360 worker group:

```bash
cd /var/www/vistaexpressbusiness/backend
php artisan config:cache
sudo supervisorctl restart 'vistaexpressbusiness-worker:*'
sudo -u www-data php artisan optical:check-messaging --smtp
sudo -u www-data php artisan optical:check-processing
```

`--smtp` connects securely and authenticates without sending an email or printing credentials. Check this **after** rebuilding configuration. Configuration-ready does not mean authenticated or delivered. The worker must consume `communications`. Immediate submissions do not depend on cron; future sends, automatic retries and reminders require the existing Laravel scheduler. Review system cron and other users before installing a duplicate schedule entry.

Aruba recommends authenticated SMTP on port 465 using implicit TLS. The existing 587 STARTTLS option remains available for mailbox services supporting it; TLS and certificate validation remain required. References: [Aruba mailbox settings](https://guide.aruba.it/hosting-e-domini/email/configurazione-posta/parametri-configurazione-posta), [Symfony SMTP transport](https://symfony.com/doc/current/mailer.html).

## Customer communication workflow

1. Choose Email or SMS. Channel setup problems are shown before composing. Existing WhatsApp buttons continue to open the approved-template composer.
2. Choose Immediately, a future date/time, or a positive delay. The date/time input uses the device timezone and submits an explicit UTC timestamp.
3. Select customers. Customer-row and bulk-list shortcuts preselect the relevant records. Up to 100 recipients are allowed.
4. Enter content and purpose, or copy an active channel-specific template. Email requires a subject; SMS does not display that field. Existing supported personalization variables remain available.
5. Review the content, timing and recipients. Invalid addresses, archived customers, duplicate destinations and explicit opt-outs must be resolved before sending. Every recipient gets a separate message and history entry; no customer addresses are shared with other recipients.

No opt-in recording step is required. Existing explicit withdrawals, STOP/unsubscribe, tenant boundaries, permissions and sender eligibility are preserved. Immediate messages are queued directly after the transaction commits. Scheduled messages wait until their chosen time. SMTP acceptance is shown as Accepted, not falsely described as inbox delivery. Uncertain provider submissions must be reconciled before a new send to avoid duplicates.

## Vista category source comparison

Source: `opty-amazone/frontend-seller/app/products/new` and matching edit routes; `SimplifiedProductOptions`, `FrameAttributeFields`, `LensCustomization`, `ContactLensSpecificationEditor`, `ContactLensPackUnitsEditor`, `DraftColorVariationsEditor`, `EyeHygieneVariantsEditor`; original backend `ProductForm`, `SellerFormSchema`, `ProductOptions` and `ContactLensPublication`.

| Seller category | 360 publication controls and persistence |
| --- | --- |
| Eyeglasses (`frame`) | Seller frame shape/material catalogs, color palette, gender, lens notes, indexes, treatments, lens colors, color-specific photographs, option prices, default/sort settings, frame measurements and sizes. |
| Sunglasses (`sunglasses`) | Same supported frame controls, with the correct category identity and seller options. |
| Contact lenses (`contact_lens`) | Details → Prescription options → Packs and pricing; hierarchical category/lens-type selection, brand/material/replacement/UV/medical-device fields, prescription values and per-eye settings, pack quantities/prices/photos/colors, purchase quantities and pack/color allocation. |
| Eye hygiene (`eye_hygiene`) | Listing size/pack/expiry; size-volume rows with prices/costs/SKU/expiry/stock/active flags; product-use variants with description/price/active flag. Both option types now use a single-photo picker and uploader rather than a photo-number field. |
| Accessories (`accessory`) | Basic details, seller gender choices, retail/cost/compare prices, stock status, gallery and listing visibility. |

The pages fetch the actual seller/store metadata. The original backend supplies options, store field settings and validation. No duplicate static category catalog is introduced. The previously approved integration rule remains: a seller allocates a chosen quantity from 360 to Vista; editing a listing defaults the new allocation and every nested option allocation to zero. Product identities, optimistic version checks, image ownership and idempotent outbox keys remain enforced. Standalone product creation stays separate.

Shop route: `/products/:id/vista` (under the configured application base). Admin route: `/workspace/vista-publish?shop=<id>&record=<product-id>`. Both use existing layouts and controls. Product details offer the entry button; publication forms no longer use dialogs. Existing saved numeric hygiene photo mappings are converted to selected photo identities on edit.

## Seller authorization page

The dedicated page is served by the **original Vista backend**. It now uses its own dark editorial layout, inset model portrait, connection identity and responsive form. EN/IT links preserve the exact OAuth state, PKCE challenge and original expiry. The submitted language is explicit, and seller-store eligibility errors are localized while preserving HTTP 403. Passwords, grants, redirects and existing seller/mobile sessions retain their previous security behavior.

Deploy its controller, Blade view, CSS and existing image on the original Vista host and clear that backend's compiled views. Pulling only 360 cannot update this screen. The stylesheet has a release query parameter so an old CSS cache does not retain the previous layout.

## Release and acceptance

Deploy 360 Backend, Frontend, Admin, shared contracts and original Vista Backend. The shared deployment copy must include `operations/MessageWizard.tsx`. Build both portals and use the existing static publisher to deploy `dist` into the configured `www` roots. Keep each portal's correct base path; building alone does not change Nginx's served directory.

Controlled production acceptance remains required:

- Run `optical:check-messaging --smtp`; confirm configuration and authenticated TLS success. Confirm the communications worker heartbeat.
- Send one email to an authorized test recipient using Immediately. Check a single history entry, provider Accepted status and actual inbox/spam receipt. Repeat a scheduled message and two-recipient bulk email; each recipient must receive only their own message.
- Remove a required setting in a test environment: a precise readiness error appears and no Delivered status is fabricated. Restore it, refresh config/restart workers, then retry the eligible blocked message. Check wrong SMTP credentials and explicit opt-outs.
- Sign into Vista in Italian and English; test missing fields, wrong credentials, unverified/inactive seller, expiry, successful callback and existing Seller Panel session continuity.
- In each category, create a draft CRM product, upload/scan its photos, activate it, open the dedicated Vista page, complete the category form and allocate stock. Check the original Seller Panel listing, photographs, options and stock. Reload/edit without allocating additional stock; confirm the same remote product and unchanged stock totals.
- Test a stale version, invalid category/option, foreign-shop image/customer/product ID and unauthorized employee/Admin scope. Backend restrictions must still apply.

Local test evidence and pending production acceptance are recorded in `progress.md`. Local mocked email tests cannot verify the user's production mailbox credentials or inbox delivery.

## Local verification completed

- Messaging backend: 26 tests / 333 assertions. Final focused Aruba/processing diagnostics: 7 tests / 32 assertions (overlapping regression selection).
- 360 integration and standalone product variations: 13 tests / 173 assertions. Original Vista integration: 13 tests / 185 assertions.
- Browser checks: all five category create/edit round-trips in Shop and Admin; standalone variation photos/SKUs/stock; immediate bulk Email/SMS; scheduled-email validation; existing WhatsApp composer; customer/reminder/follow-up/payment/report workflows; EN/IT authorization and callback. Desktop/mobile seller-page screenshots reviewed.
- Final Shop and Admin TypeScript checks, focused lint, and production builds passed. Existing bundle-size and frontend Browserslist-age advisories remain.
- Deployment shared-source copies are hash-identical for the five changed/new shared modules. No database migration is introduced in this release.

Production SMTP authentication, inbox delivery and inspection of the deployed Vista listings are still acceptance gates. Deploy and run the server checks above before marking those gates complete.
