# Login Location Tracking — Audit & Fix Plan (2026-11-17)

## What already existed

| Piece | Where | State |
|---|---|---|
| IP geolocation (ipapi.co / ip-api.com), private/loopback exclusion, 12 h cache, Null Island guard | `app/Services/IpGeolocationService.php` | Working |
| After-response lookup on `login_succeeded` / `login_failed` | `AuthenticationAuditService::record` → `ResolveLoginGeolocation` | Working |
| `login_geolocations` table, 90-day `Prunable` retention, hourly backfill command | migration `2026_11_14_040000`, `routes/console.php` | Working |
| Leaflet + OpenStreetMap (single pin and overview map) | `wicars-ui/src/pages/vpaa/activityLog/SingleLoginMap.tsx`, `LoginMap.tsx` | Working, only renders when IP lookup resolved |

## Why the Details page shows an empty map and `127.0.0.1`

1. **Local development routes every request through loopback.** The Vite dev server proxies `/api` to
   `http://127.0.0.1:8000` without `X-Forwarded-For`, so Laravel's `$request->ip()` is always `127.0.0.1`.
   The service correctly classifies loopback as `private` and the UI shows "Signed in from a private network".
   This is expected behaviour, not a lookup bug — no public IP exists to geolocate.
2. **No trusted-proxy configuration.** In production behind a reverse proxy / load balancer / Cloudflare /
   tunnel, `$request->ip()` returns the proxy's address (often private), producing the same empty result.
3. **No device-level location source.** IP was the only source, so any private/campus network sign-in could
   never be mapped.
4. **UI copy stated "WICARS never uses device GPS"** and labelled every pin "Approximate", which must change
   once device location is captured.

## Priority order

| # | Fix | Why first |
|---|---|---|
| P1 | Configurable trusted proxies (`TRUSTED_PROXIES`) + `xfwd` on the Vite proxy | Without the real client IP, the IP fallback is useless in production. Zero schema change. |
| P2 | Capture browser Geolocation (opt-in, non-blocking) on password and Google sign-in, for success and failure | Only source that works on private/campus networks. |
| P3 | Store device coordinates, accuracy and capture time (additive nullable columns on `login_geolocations`) | Required to persist P2; inherits the existing 90-day prune. Audit rows untouched. |
| P4 | Expose `source` (`device` / `ip` / `none`) and device fields from the API | One authoritative rule for which location to show. |
| P5 | Details UI: three explicit states — **Precise device location**, **Approximate network location**, **Location unavailable**; accuracy ring; lat/lng/accuracy/source fields; corrected privacy copy | IP estimates must never read as an exact physical location. |

## Design decisions

- **Consent:** the browser permission prompt is the consent gate. It is requested only when the user starts
  interacting with the sign-in form (not on page load), next to a one-line notice. Denial, timeout or an
  unsupported browser sends nothing and sign-in proceeds unchanged.
- **Never blocks authentication:** the client waits at most ~2.5 s for a fix at submit; the server silently
  drops malformed device location instead of returning 422. Location is stored after the response.
- **Validation:** latitude ∈ [-90, 90], longitude ∈ [-180, 180], not (0, 0), accuracy 0–100 000 m, capture
  time within the last 10 minutes and not in the future. Coordinates rounded to 5 decimals (~1 m).
- **Not in the audit log:** device coordinates live only in the prunable `login_geolocations` table, never in
  the append-only `authentication_audit_logs.metadata`, so the 90-day retention applies.
- **Both sources kept:** when a device fix exists, the IP lookup still runs; the network city is shown as
  secondary context (useful for spotting VPN/mismatch).
- **Overview map unchanged:** the Login Locations aggregate map keeps city-level IP pins; precise device points
  are shown only on the per-sign-in Details page.

## Known limits / follow-ups

- Failed Google sign-ins happen in the server-side OAuth callback, so they carry IP location only.
- Sign-ins with a device fix but a private IP do not appear on the overview map (it filters on resolved IP).
- Production must set `TRUSTED_PROXIES` to the actual proxy addresses (do not use `*` unless the app is
  reachable only through the proxy).
