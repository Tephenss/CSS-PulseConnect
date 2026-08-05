# CCS PulseConnect — Security Deploy Checklist

Apply these steps **in order** before putting real student data in production.

## 1. Hostinger `.env`

```env
SUPABASE_DEV_SKIP_SSL_VERIFY=false
SMTP_DEV_SKIP_SSL_VERIFY=false
SESSION_COOKIE_SECURE=auto
MOBILE_PUSH_API_KEY=<long-random-string-at-least-32-chars>
```

- Keep SSL verify **false** (do not skip). Upload `certs/cacert.pem` with the site so PHP can verify HTTPS to Supabase.
- If login shows `unable to get local issuer certificate`, re-upload `includes/curl_ssl.php`, `includes/supabase.php`, and `certs/cacert.pem`.
- Generate a new key (e.g. `openssl rand -hex 32`).
- Put the **same** value in Flutter `lib/config/env.dart` as `mobilePushApiKey`.
- Empty `MOBILE_PUSH_API_KEY` now returns **503** (fail closed).
## 2. Supabase SQL

1. Open Supabase → SQL Editor.
2. Run in order (if not already applied):
   - [`048_security_lockdown.sql`](supabase/migrations/048_security_lockdown.sql)
   - [`049_advisor_rls_critical_fix.sql`](supabase/migrations/049_advisor_rls_critical_fix.sql)
   - [`050_advisor_warn_hardening.sql`](supabase/migrations/050_advisor_warn_hardening.sql)
   - [`051_drop_attendance_write_temps.sql`](supabase/migrations/051_drop_attendance_write_temps.sql) — after deploying PHP + Flutter scan/eval/absence via BFF
   - [`052_drop_remaining_write_temps.sql`](supabase/migrations/052_drop_remaining_write_temps.sql) — after deploying Phase B (event create / assistants / proposals via BFF)
   - [`053_advisor_info_locked_deny_policies.sql`](supabase/migrations/053_advisor_info_locked_deny_policies.sql) — explicit deny policies on locked tables (clears Advisor INFO; does **not** reopen anon)
3. Confirm:
   - Table `mobile_sessions` exists.
   - Anon can **no longer** `select *` from `users`, `password_reset_codes`, `email_verification_codes`, `trusted_devices`, student-doc tables, or `fcm_tokens`.
   - Anon can **no longer** `insert/update/delete` on attendance, tickets, events, assistants, certs, proposals, evaluation answers/questions.
   - Advisor INFO “RLS Enabled No Policy” on those locked tables is cleared via `*_deny_clients` (`USING false`) — still fail-closed for anon.

### Smoke test (must fail)

With the **anon/publishable** key (not service role):

```http
GET /rest/v1/users?select=id,email,password&limit=1
```

Expected: empty / permission denied / RLS violation — **not** user rows.

## 3. Flutter release

1. `flutter pub get` (adds `flutter_secure_storage`).
2. Ensure `mobilePushApiBaseUrl` points at `https://ccspulseconnect.com`.
3. Match `mobilePushApiKey` to Hostinger.
4. Rebuild APK/AAB and ship. Old installs without session tokens must log in again.

## 4. Functional smoke test

- [ ] Teacher / assistant QR scan (via `mobile_scan_ticket.php`)
- [ ] Teacher create event + assign assistant (via `mobile_secure_write`)
- [ ] Teacher proposal upload + submit for review
- [ ] Student evaluation + absence reason submit
- [ ] Change password (mobile PHP; no direct `users` update)
- [ ] Student login (PHP session token issued)
- [ ] Teacher login + OTP / IP trust
- [ ] Forgot password (send → verify → update) without direct Supabase writes
- [ ] Email verification OTP
- [ ] Event registration (mobile PHP API)
- [ ] Student document upload/submit
- [ ] My Tickets loads
- [ ] Web admin login over HTTPS (Secure cookie)

## 5. What this lockdown does

| Area | Change |
|------|--------|
| Mobile API key | Required; empty = 503 |
| Mobile identity | Opaque session after login; `user_id` cannot be forged |
| Auth / OTP / reset | Server-side only (service role) |
| Sensitive tables | Revoked from `anon` |
| Document buckets | Private; use signed URLs |
| Session cookies | Secure when HTTPS |
| SSL verify | Default on |

## 6. Residual risks (follow-up)

- **Locked hard (no anon write):** auth tables, attendance/tickets/evals, **events / sessions / assistants / assignments / certs / proposals** (after 051+052 + new APK).
- **SELECT still open** on many catalog tables for app reads — next hardening can move those behind `mobile_secure_read` and drop SELECT-for-anon.
- Web admin/teacher cookie APIs still write via **service role** (expected).
- Shared campus IP trust is intentional but weaker than per-device crypto keys.
- Rotate `MOBILE_PUSH_API_KEY` if an old APK leaked it.

## 7. Performance on Free Nano (no paid upgrade)

- **Supabase** holds critical/sensitive data (source of truth).
- **Firebase FCM** assists: push wakes clients so the app/web need not poll Supabase every few seconds.
- **Firebase Firestore (assist only):** `public_catalog_events/{id}` + `public_catalog_meta/signals` cache **public published** event list fields. Synced by PHP on publish/archive via the same service-account JSON used for FCM. Clients may read the catalog to spare Nano; registration/attendance/tickets still go through PHP + Supabase.
- **Scan ingress middleware (assist only):** mobile attendance writes go **Firestore ingress → Supabase** (with PHP file-queue fallback). Collections: `scan_ingress_signals/{eventId}` (read-only aggregate: `pending_count`, `revision` — no names/tickets) and `scan_ingress_jobs/{jobId}` (server-only hashed job metadata). Implemented in `includes/firestore_scan_middleware.php` + `includes/mobile_scan_write.php` for `mobile_scan_ticket.php` and `mobile_event_self_checkin.php` (`self_check_in` / `self_check_out` kinds).
- **Student Event QR time-in/out:** `api/mobile_event_self_checkin.php` (session student) — time-in uses stored `grace_time` / `scan_window_minutes`; time-out at `end_at`+1h or Early Out (`early_out_enabled_at`+1h). Teacher Early Out: `api/mobile_event_early_out.php` / `api/event_early_out.php` (ownership-checked). Eval answers: `evaluation_upsert` (checkout-gated). Auto-cert: separate BFF action `certificate_auto_issue` via `includes/certificate_auto_issue.php` (checkout + eval complete + **FIFO registrar code from `event_certificate_codes` pool** + idempotent; service role only — no Flutter→Supabase cert writes). Cert design library is standalone (`certificate_templates.event_id` nullable); coded PPTX/PDF **or manual code paste** via `api/event_certificate_import.php` (teacher ownership-checked; optional link of saved template to event; anon writes revoked on pool tables).
- Deploy Firestore rules from [`firebase/firestore.rules`](firebase/firestore.rules): public **read**, deny client **writes**.
- After first deploy, admin can POST `/api/firestore_catalog_rebuild.php` (CSRF + admin session) once to backfill published events.
- Web/app use longer TTL caches + slower live refresh (manage events, notifications, scan). Reupload PHP + rebuild app after those changes.
- Peak school days may still need stronger compute later; caching cannot replace Nano hardware limits forever.

### Firestore must NOT store

users/passwords, OTP codes, trusted devices, attendance rows, ticket tokens, student names/photos, registrations, student docs, mobile sessions, or notification PII.

### Concurrent / load hardening (no flow change)

- Web auth daily-verify + device trust: PHP session TTL (~10 min), IP + Manila-day bound.
- Manage Events list/live caches use a shared generation bump on create/approve/archive/update.
- Event view + registration capacity use `Prefer: count=exact` (no multi-thousand row downloads).
- Mobile my-tickets: explicit columns + `limit=150` via session-authenticated PHP only.
- Participants: throttle absent backfill writes; skip legacy attendance fetch when primary rows exist.
