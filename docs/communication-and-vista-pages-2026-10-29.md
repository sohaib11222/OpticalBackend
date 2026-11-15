# Customer communication and Vista publishing pages

## Current customer-contact policy — 15 November 2026

This release note keeps the Vista product-publication material below. Its older
Twilio/SMS instructions are superseded by
[Customer email and WhatsApp Web](customer-messaging-setup.md).

Customer Communication now has one CRM delivery channel: **email via Aruba
SMTP**. Immediate mail safely runs after the request transaction commits;
scheduled mail uses the Laravel scheduler and `communications` worker. SMS,
Twilio callbacks and automated WhatsApp delivery are removed. Customer-table
WhatsApp buttons use official WhatsApp Web click-to-chat and never send a
message automatically.

Email setup belongs in the 360 Laravel backend. Configure
`COMMUNICATIONS_EMAIL_ENABLED=true`, `COMMUNICATIONS_EMAIL_SYNC=true`, the
Aruba mailbox values and public HTTPS API URL, then run
`php artisan config:cache`, restart the 360 worker and execute
`php artisan optical:check-messaging --smtp`. The full secure server checklist
is maintained in the linked setup guide.

## Current communication workflow

1. Choose Email, immediate or scheduled timing, recipients, content and review.
2. A valid email address is required. New messages require no consent record;
   explicit email opt-outs/unsubscribes remain enforced.
3. Bulk email is all-or-nothing and creates a separate personalized history
   record for every recipient.
4. `Sent` means the SMTP server accepted the email. Inbox delivery/opening is
   not falsely claimed. Failed and uncertain outcomes remain visible for review.
5. Use a customer WhatsApp button to open WhatsApp Web and send manually.

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
- Browser checks: all five category create/edit round-trips in Shop and Admin; standalone variation photos/SKUs/stock; immediate and scheduled email validation; WhatsApp Web click-to-chat; customer/reminder/follow-up/payment/report workflows; EN/IT authorization and callback. Desktop/mobile seller-page screenshots reviewed.
- Final Shop and Admin TypeScript checks, focused lint, and production builds passed. Existing bundle-size and frontend Browserslist-age advisories remain.
- Deployment shared-source copies are hash-identical for the five changed/new shared modules. No database migration is introduced in this release.

Production SMTP authentication, inbox delivery and inspection of the deployed Vista listings are still acceptance gates. Deploy and run the server checks above before marking those gates complete.
