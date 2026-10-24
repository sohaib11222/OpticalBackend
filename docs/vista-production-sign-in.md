# Vista Express 360: production seller sign-in and branding

## Diagnosis (24 October 2026)

The public `https://api.vistaexpressbusiness.com/api/v1/auth/vista/config` returned
`{"data":{"enabled":false}}`. The old frontend hid the seller button for this
response and silently swallowed configuration request errors. This is server-side
configuration, not a missing Vite build flag. The updated frontend always shows
the seller option, disables it while unavailable, and offers an availability retry.
Normal account login remains usable. No client secret belongs in React or VITE variables.

## Server configuration

1. Deploy these backend and frontend changes (and Admin for branding/styles).
   The marketplace's `resources/views/optical/authorize.blade.php` also has the
   updated application name; deploy that view to update seller consent branding.
2. In the CRM backend `.env`, set the following. The URLs below assume the shop is
   actually served at `https://api.vistaexpressbusiness.com/pulse-ui`. If it is on
   an apex host instead, use that exact public frontend URL for SHOP_URL and callback.

```dotenv
APP_DISPLAY_NAME="Vista Express 360"
MAIL_FROM_NAME="Vista Express 360"
ARUBA_FROM_NAME="Vista Express 360"
VISTA_ENABLED=true
VISTA_BASE_URL=https://api.vistaexpress.it
VISTA_CLIENT_ID=optical-shop
VISTA_CLIENT_SECRET=<existing shared server secret, at least 32 characters>
SHOP_URL=https://api.vistaexpressbusiness.com/pulse-ui
VISTA_REDIRECT_URI=https://api.vistaexpressbusiness.com/pulse-ui/auth/vista/callback
```

3. On the **Vista marketplace backend**, confirm:

```dotenv
OPTICAL_INTEGRATION_ENABLED=true
OPTICAL_CLIENT_ID=optical-shop
OPTICAL_CLIENT_SECRET=<the same shared server secret>
OPTICAL_REDIRECT_URI=https://api.vistaexpressbusiness.com/pulse-ui/auth/vista/callback
OPTICAL_ALLOW_LOOPBACK_REDIRECT=false
```

Reuse the existing secret securely; do not post it in chat, Git, screenshots or
browser environment variables. The marketplace supports one exact configured
callback: changing a localhost callback to production means that local seller
authorization will need its own separate provider environment/configuration.

4. On each backend, from its own directory:

```sh
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

5. On the CRM backend run `php artisan optical:check-vista`. This validates local
   configuration without printing secrets or contacting the provider. Success
   does not prove the provider configuration matches; complete the browser test.
6. Rebuild both React apps with `npm ci && npm run build`; deploy their `dist`
   output. Keep the sibling `contracts` sources available when building.
7. Serve the frontend callback through the SPA fallback. Preserve same-origin
   `/api` and `/sanctum` reverse proxying to the CRM backend, HTTPS, session cookies
   and existing CSRF settings. Do not proxy them to the marketplace API.

Keep existing `APP_NAME`, session cookie names and OAuth client ID unchanged.
`APP_DISPLAY_NAME` controls user-visible Laravel branding separately, avoiding
unnecessary logout or changes to cache/session identifiers. Explicit existing
MAIL_FROM_NAME / ARUBA_FROM_NAME environment values override application defaults.

## Acceptance checks

- Request `/api/v1/auth/vista/config`: expect enabled=true, no credentials in response.
- Open login in a signed-out browser. Vista button is enabled. Click it, authorize
  on Vista using an existing seller, and return to the same public frontend.
- Confirm seller/shop identity, refresh persistence, and tenant-scoped Vista data.
- Cancel authorization or tamper with callback state: no account should be signed in.
- If disabled, the button stays visible with an unavailable explanation. After
  server configuration is corrected, Check again enables it without reconnection.
- Simulate an API failure: an error and retry appear; ordinary login still works.
- Check frontend/Admin titles and English/Italian branding. Check Admin language
  and profile menus in light/dark themes, on mobile and with keyboard focus. No
  persistent blue border; keyboard focus remains visible.

Live environment changes and real seller acceptance remain deployment checks;
local tests never modify production accounts or data.
