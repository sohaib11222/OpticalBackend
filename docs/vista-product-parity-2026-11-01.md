# Vista publication parity — 1 November 2026

Status: Local implementation and automated acceptance passed. Production deployment,
live account acceptance and final live visual comparison remain Needs Testing.

## Reference inspection

Inspected the live Seller create pages for eyeglasses, sunglasses, contact lenses,
eye hygiene and accessories, plus the contact-lens edit page. Eyewear uses color
summary cards and a modal containing color, pricing, stock, sizes and photos.
The contact-lens create screen starts with listing details; prescription and pack
editors are tabs on the saved product. Hygiene has size/pack and named variants.
Accessories have the basic listing and gender fields. Preserve these actual
differences rather than assuming all categories have frame variations.

The live buyer example /products/48 exposes colors and color-dependent sizes;
selecting a size enables purchasing. No live product was created or changed.

## Confirmed gaps addressed locally

- Initial CRM publication ignored primary_image_id and featured the oldest image.
  Initial selection now respects primary_image_id, while saved gallery order wins
  on subsequent edits. Shared image picker supports choosing a featured photo.
- Vista's integration schema restricted lens indexes/treatments to a fixed list,
  while Seller accepts comma-separated custom values. Schema now uses lists.
- Shop/Admin color editors now use summary cards and a focused modal with draft
  edits, Save/Cancel, validation and default-color exclusivity.
- Listing sections reordered: basic, colors, pricing, photographs, category
  options, visibility. CRM theme retained.
- Added scanned WebP ingestion normalized to PNG before downstream publication;
  gallery drag ordering plus keyboard-accessible move/featured controls; color
  stock totals calculated from sizes.
- Color-only galleries now work without a main photo, matching Seller eyewear.
  Ownership checks and the requirement for a usable main/color photo remain.
- Prescription values now use cards, Add/Edit dialogs, eye tabs, bulk entry and
  right-to-left copying. Pack-size entry builds editable pack cards. Size rows
  now use a compact table and parse the Seller's 52-18-140 notation.
- Editing a color with zero additional stock preserves its availability; an
  allocation is not the current stock quantity on Vista.
- Added direct HTTPS image import to both portals through the existing upload
  quarantine/scanner. The backend rejects private/reserved IPs, embedded
  credentials, redirects, non-images and files over 5 MB; pins DNS to a checked
  address; and does not expose URL query parameters in error messages.

## Verification evidence

- Original Vista PHPUnit OpticalIntegrationTest: 13 tests / 260 assertions passed.
  Extended five-category test compares Seller-created vs integration-created
  products through PublicProductController and the real buyer cart API. It also
  checks two distinct color/size selections and rejects a mismatched size/color.
- Shared contract tests: 2 passed, including primary photo and saved ordering.
- CRM catalog and remote-photo regression: 11 tests / 157 assertions passed,
  including WebP normalization, URL quarantine, DNS pinning and unsafe URLs.
- CRM Vista integration regression: 10 tests / 133 assertions passed, including
  empty main galleries with owned color photos and idempotent allocation retries.
- Shop/Admin TypeScript checks and production builds passed. Existing bundle-size
  and outdated Browserslist advisories remain; no build errors.
- Browser all-category create/edit/Admin test passed (one scenario covering all
  five categories). It creates a standalone product, uploads/scans its photo,
  publishes, reloads and edits through Shop and scoped Admin, checks the public
  provider record, and verifies the same remote identity and remaining shop stock.
- Actual local Buyer app: five browser tests passed, one per category. Images load;
  eyewear color/size selection enables purchasing; contact lenses show the edited
  EUR 31.50 pack price, base curve 8.60, power -1.50 and right-to-left copying;
  hygiene size selection and accessory purchasing controls work. All five also
  passed the 390px viewport horizontal-overflow check. No checkout/payment made.
  These tests use the real isolated provider data created by the CRM acceptance
  run, not mocked product responses. A first test used 8.6 instead of the actual
  formatted 8.60 selector; corrected test passed without changing Buyer behavior.
- Eyewear color image limit now matches Seller's six-photo control; main/contact
  galleries retain their respective limits. Default listing status is In stock,
  while explicit saved statuses remain unchanged.
- Three deployment contract files synchronized and content hashes verified:
  VistaEditor.tsx, vista-form.ts and operations/locale.ts.
- Final 2 November rerun passed all five Shop/Admin categories after the pack
  editor state cleanup, including adding/removing pack sizes while retaining
  existing prices. Assertions compare monetary amounts numerically because
  number inputs normalize 29.50 to 29.5. Shared-component lint has zero errors;
  final Shop root-base and Admin production builds both passed.
  Final browser runner exited successfully: 1 passed (6.7 minutes). Windows left
  its disposable server tree alive after assertions; scoped cleanup of that
  verified test tree allowed normal runner completion. No user service was stopped.
- Read-only live API inspection: product 48 (Seller eyeglasses) has no main
  gallery, three color variants with individual photos and seven linked sizes.
  Product 49 is contact lenses with a red variant at zero stock. That observation
  alone does not establish why its stock is zero or prove a production defect.

## Outstanding acceptance

- Final live visual comparison and live-account publication are still outstanding.
  In-app browser inspection failed when binding the existing Seller/Buyer tabs;
  the Seller tab also showed an earlier offline error. No production changes made.
- Release both CRM portals, shared contracts and both backends together, following
  the release checklist below. No deployment has been performed by this task.
- Existing incomplete live records require review/republication; code changes do
  not reconstruct missing details or automatically allocate more live stock.

## Coordinated release checklist

1. Commit/push the original Vista backend schema, publication controller and tests
   in opty-amazone. Commit/push the 360 backend, both portals and the independent
   deployment-contracts repository. Keep credentials and generated builds out of
   source commits. Preserve and reconcile any server-only source edits first.
2. Update the original Vista checkout at `/var/www/vistaexpress/optyamazone`, then
   refresh its existing Laravel configuration/routes using its normal deployment
   procedure. This update introduces no database migration. Do not run commands
   in the unrelated 360 backend when deploying the original Vista provider.
3. Update `/var/www/vistaexpressbusiness/backend`. Refresh its Laravel caches and
   restart only `vistaexpressbusiness-worker:*`. Keep the existing upload scanner,
   queue names and scheduler active. Run `php artisan optical:check-processing`
   as www-data. URL photo imports require PHP cURL/fileinfo and image processing
   requires GD with WebP support; an imported URL must be public HTTPS without a
   redirect. The same quarantine scanner checks imported and uploaded files.
4. From the 360 backend, release each portal with the existing verified publisher:

   ```bash
   node deploy/release-portal.mjs frontend https://vistaexpressbusiness.com
   node deploy/release-portal.mjs admin https://admin.vistaexpressbusiness.com
   ```

   It pulls the portal and sibling contracts repositories, builds with `/` base,
   publishes into the actual `www` root and verifies public hashes. Stop on a dirty
   or divergent checkout; do not reset server edits or bypass the release guard.
5. In an authorized test shop, repeat the acceptance matrix below. Capture remote
   product IDs and compare Seller editing and Buyer views. Do not place a paid order.

## Live acceptance matrix

| Category | Create, reload and edit | Buyer acceptance |
| --- | --- | --- |
| Eyeglasses | Two colors, distinct photos, sizes such as 52-18-140, prices and custom lens options | Each color loads its own images and sizes; selected size price and cart line agree |
| Sunglasses | Same variation checks plus all applicable sunglasses details | Color/size selection, photographs and price agree with Seller-created reference |
| Contact lenses | Correct subcategory/type, right/left prescriptions, pack sizes/prices/photos, pack colors and allocated stock | Pack changes price/photo; available prescriptions are selectable; cart preserves chosen pack/color/eyes |
| Eye hygiene | Size/volume, pack type, expiry where used, named options and individual photos | Option selection changes the relevant photo/price and purchasing uses the selected option |
| Accessories | Basic listing, gender, pricing, photos and visibility | Correct gallery, details, availability and cart price |

For every category: try missing required values, invalid images, allocation greater
than shop availability, an unauthorized shop and a repeated publication request.
Expect actionable validation and no duplicate listing or duplicate allocation.
Reopen the mapping and update a description/photo with **Quantity to allocate now =
0**; remote identity and remaining stock must stay unchanged. Choose a positive
quantity only when transferring additional stock. Standalone child variations
remain separate SKU/stock records; the integration must not silently combine them.

Old incomplete listings: open the existing CRM product's **Update Vista Express /
allocate stock** page, correct its mapping and photos, then publish with zero extra
allocation. Review live quantities before any correction; do not create a second
product to repair the first or infer missing historical data.

## Local evidence and test commands

From OpticalsShop: `node node_modules/@playwright/test/cli.js test
tests/product-upgrade.spec.ts -g "Vista category editors" --reporter=list` runs the
isolated Shop/Admin/provider acceptance workflow. The opt-in
`tests/vista-buyer-parity.spec.ts` then checks its saved data with the actual Buyer
app on port 5280 and isolated provider on 8200 (`E2E_BUYER_PARITY=1`, no webServer
reset). Do not point these tests at production or a real shop database.

Screenshots are recorded in the acceptance test output and `.tmp/buyer-parity-final`.
They use clearly synthetic test product photos. No live products, customer records,
orders or allocations were created or modified during local verification.
