# KBSAVE

Loan, deposit and daily collection management. Core PHP 8.2 REST API + MySQL 8 + React (Vite, TypeScript).
Specs: [docs/](docs/).

## Run locally

Requires Docker and Node.

```bash
cp api/.env.example api/.env
# set APP_KEY in api/.env:  php -r "echo base64_encode(random_bytes(32));"
docker compose up -d --build                                   # API on :8095, MySQL on :3307
docker compose exec api php bin/migrate.php
docker compose exec -e SEED_PASSWORD=Admin@12345 api php bin/seed.php --demo

cd web && npm install && npm run dev                           # http://localhost:5180
```

Demo users (password from `SEED_PASSWORD`):

| Email | Role | Sees / does |
|---|---|---|
| admin@finserve.local | Super Admin | everything, settings, compliance gate |
| manager@finserve.local | Branch Manager | City Branch; approves loans and payouts |
| officer@finserve.local | Loan Officer | creates customers, applies for loans, opens deposits |
| agent1@ / agent2@finserve.local | Collection Agent | assigned customers only; collects, logs visits, hands over cash |
| supervisor@finserve.local | Collection Supervisor | assigns routes, verifies cash handovers |
| finance@finserve.local | Finance | disburses, confirms digital payments, reverses, pays payouts, resolves cash |
| auditor@finserve.local | Auditor | read-only reports, ledger and audit log |

Customer portal logins are created from a customer's page ("Enable portal login").

## Android apps

Two apps are built from the same React code (Capacitor), each with its own phone UI and bottom tabs:

| APK | Package | For | Tabs |
|---|---|---|---|
| `dist-apk/KBSAVE-Agent.apk` | `in.finserve.agent` | collection agents | Today · Customers · Payments · Cash · Me |
| `dist-apk/KBSAVE-Customer.apk` | `in.finserve.customer` | customers | Home · Help · Alerts · Me |

```bash
./scripts/build-apk.sh                                   # server: https://kbsave.in
API_SERVER=http://192.168.1.5:8095 ./scripts/build-apk.sh   # test build against this Mac
```

- Requires JDK 21 and the Android SDK (platform 36) — installed under `~/Library/Java` and `~/Library/Android/sdk`.
- Release APKs are signed with `mobile/keystore/finserve-release.jks` (password in `keystore.properties`, both
  git-ignored). **Back these up** — Play Store / in-place updates need the same key.
- The server address is fixed at build time (people cannot change it in the app). Apps use bearer tokens (no cookies) and the API allows
  their origin via `CORS_ORIGINS`. For production use **HTTPS**; plain HTTP is enabled only so a phone on the same
  Wi-Fi can reach the dev server.
- Agents keep offline collection (encrypted queue) and UPI QR collection in the app; the Agent app refuses customer
  logins and vice versa.

### UPI QR collection

1. Admin → Settings → *UPI QR collection*: enter the organisation's UPI ID (VPA) and payee name.
2. Agent → Collect → **UPI QR** → *Show QR*: a `upi://pay` QR with the exact amount, payee and a unique reference.
3. Customer scans and pays in any UPI app. The agent enters the **12-digit UTR** and/or attaches a **screenshot**.
4. The payment is *pending*; finance opens it, sees the screenshot, enters/checks the UTR and confirms (receipt
   issued). A UTR can be used only once; screenshots are type-checked and stored outside the web root.

## Tests

```bash
./scripts/test.sh     # WIPES the local dev database, re-seeds, runs both API suites
```

- `api/tests/finance_test.php` — full money flow: loan approval (maker-checker), disbursement, idempotent payments,
  late fees, allocation order, settlement, reversal, cash handover with discrepancy, deposits gate, accrual,
  maturity payout, customer portal scope, reports, audit. Asserts the trial balance balances after every step.
- `api/tests/features_test.php` — holiday calendar, statements, KYC uploads (content-type sniffing, scope, audit),
  service requests, notifications/SMS outbox, dashboard insights, cookie sessions + CSRF, two-factor sign-in
  (setup, replay protection, enforcement, admin reset, no self-lockout).
- `api/tests/core_banking_test.php` — accrual accounting, advance interest, SMA/NPA classification and unrealised
  interest reversal, provisioning, write-off + recovery, approvals, approval/exposure/cash limits, treasury funds
  check, manual journals, UPI settlement, P&L/balance sheet, day-end close. GL ↔ subledger controls after every step.
- `api/tests/smoke_test.php` — auth, permissions, customer scope, loan product versioning and EMI maths.

## Scheduled jobs

| Script | When | Does |
|---|---|---|
| `api/bin/daily.php` | daily after midnight | late fees, deposit interest accrual, maturity, due/overdue reminders |
| `api/bin/send-notifications.php` | every 5 min | delivers queued SMS via `SMS_DRIVER` (`none` keeps them queued; `log` writes `storage/logs/outbox.log`) |

## Daily job (detail)

`api/bin/daily.php` applies late fees, accrues deposit interest (one journal entry per branch) and marks deposits
matured. Schedule once a day after midnight business time (cPanel cron):

```
15 0 * * *  php /home/USER/finserve/api/bin/daily.php >> /home/USER/logs/finserve-daily.log 2>&1
```

## What's built

| Area | Highlights |
|---|---|
| Access | Bearer tokens (hashed), login rate limit, 9 roles / 35 permissions, scope all → branch → assigned → own enforced in SQL |
| Customers | Shared profile, agent assignment history, portal login |
| Loan products | Admin-configurable rate, method (EMI/flat), frequency, fees, GST, late fee, grace, prepayment, allocation order; immutable versions |
| Loans | Apply → approve/reject (not by applicant) → disburse (ledger + schedule) → repay → settle/close; late fees; aging |
| Payments | `Idempotency-Key` required; row locks; allocation per product order; receipts with public verification; reversal never deletes |
| Ledger | Double-entry, balanced or rejected, immutable; reversals are linked mirror entries; trial balance & journal reports |
| Field | Daily collection list, visit outcomes/promises, mobile-first agent UI |
| Cash | Day-end handover, supervisor count (not by the agent), discrepancy stays open until recovered / written off |
| Deposits | **Off by default (compliance gate)**; recurring & fixed products, daily simple-interest accrual, maturity, premature penalty, payout maker-checker |
| Reports | Daily collection, portfolio & PAR30, agent cash, deposit liability, payouts, trial balance, journal; CSV export (logged) |
| Admin | Users & roles, branches, settings, audit log viewer with before/after |
| Dashboard | Colourful KPI tiles; 30-day cash/digital collections, aging, disbursed vs collected, product mix, top collectors, branches — validated colour-blind-safe palette, tooltips, table view |
| Security | HttpOnly SameSite=Strict session cookie + CSRF header (bearer tokens for API clients), TOTP two-factor (optional, or required for privileged roles), lost-phone reset |
| KYC | JPG/PNG/PDF ≤ 5 MB, type checked from file bytes, stored outside web root under random names, served only through an authorised + audited endpoint, maker-checker review |
| Customers | Portal: loan/deposit cards, statements (print/PDF), receipts, KYC upload, support tickets, notification bell |
| Support | Service requests & complaints with threaded replies, assignment, priority, status, notifications |
| Notifications | In-app bell for staff and customers + SMS outbox (payments, approvals, disbursement, payouts, reminders, cash discrepancies) |
| Field / offline | Installable PWA; agents can collect without signal — payments queue AES-GCM-encrypted on the device (non-extractable key), sync with the original idempotency key, rejections shown |
| Calendar | Weekly off-days + holidays; schedules skip them (daily loans step over, others roll forward) |

## Layout

```
api/
  public/index.php        front controller (Apache rewrites everything here)
  src/routes.php          every endpoint + the permission it requires
  src/Core/               router, request/response (JSON + CSV), PDO wrapper, validator, paginator
  src/Auth/               bearer-token auth, permissions, customer scope
  src/Controllers/
  src/Services/           Ledger, LoanService, PaymentService, DepositService, LoanTerms, DepositTerms, Money, Settings, Audit
  bin/                    migrate.php, seed.php, daily.php
  tests/
database/migrations/
web/src/                  React app (pages/, components/, api.ts, auth.tsx, hooks.ts)
scripts/test.sh
```

## Core banking design

**Ledger.** Double-entry, immutable, balanced-or-rejected. *Control accounts* (1010 agent cash, 1030 UPI clearing,
1100 loan principal, 1110 interest receivable, 1190 loan-loss provision, 2000 deposits, 2010 deposit interest payable,
2300 advance interest) are posted only by the system; manual journals are refused on them. Accounting → Controls
proves each one equals its subledger; day-end close won't run unless they all agree.

**Income recognition (accrual).** A performing loan's installment interest becomes income on its due date
(Dr 1110 / Cr 4000). Interest paid before it falls due is held as *advance interest* (2300) and released on the due
date. Each payment allocation stores the ledger account it credited, so a reversal unwinds exactly.

**Asset classification (IRACP-style).** Daily: DPD → Standard / SMA-0 (1–30) / SMA-1 (31–60) / SMA-2 (61–90) /
NPA > 90 DPD (sub-standard < 12 months, doubtful < 36, then loss). On slipping to NPA, accrued-but-unpaid interest is
reversed out of income and further interest is recognised only on receipt. NPAs upgrade only when all arrears clear.
New loans are refused for a customer with an NPA or written-off loan.

**Provisioning & write-off.** Daily true-up of 1190 at configurable rates (0.4 % / 15 % / 25 % / 100 %). Write-off
(NPAs only) is maker-checker: Admin/Super Admin approval; principal leaves the book against the provision;
later collections are booked to *Recovery of loans written off* (4040).

**Maker-checker.** Payment reversals, write-offs and manual journals are *requests*; a different user holding the
approval permission executes them from Approvals. Loan approval has a delegated limit (Branch Manager ≤ Rs 2 L by
default; above needs Admin).

**Limits & treasury.** Per-customer exposure cap; collector cash-in-hand cap; disbursements/payouts can't exceed the
branch's vault cash (cash) or bank balance (unless overdraft is enabled).

**Reconciliation & close.** UPI collections sit in clearing until matched to a bank credit (amount must equal payments
− fees; fees expensed). Day-end close checks trial balance, controls, accruals, pending handovers and confirmations,
then locks the books — nothing can be posted on or before a closed date.

## Conventions

- Money: `DECIMAL(19,4)` in MySQL, decimal **strings** in JSON, `bcmath` in PHP. Never floats.
- Every financial event posts one balanced journal entry in the same DB transaction as the business change.
- Business dates use the configured business timezone (default Asia/Kolkata); timestamps are UTC.
- Out-of-scope records return 404, not 403. Every change is in `audit_logs` with the `X-Request-Id`.

## Before production (not done here)

- **Legal/compliance sign-off** before enabling deposits; **accountant sign-off** on the ledger mapping in
  `api/src/Services/Ledger.php` (cash-basis interest and fee recognition, GST, penalties).
- Choose and wire an SMS/WhatsApp provider in `bin/send-notifications.php`, and a payment provider (UPI webhooks
  with signature checks) — both are provider-specific and were left as clear extension points.
- Turn on "Require two-factor sign-in" in Settings once admins have enrolled.
- Have your accountant confirm the chart of accounts, recognition policy (accrual on due date; cash basis for NPAs and
  penal charges), provisioning rates and write-off policy; and the regulator's classification rules for your licence.
- Record real opening balances (capital, borrowings, bank, branch cash) as approved manual journals before go-live.
  `seed.php --demo` posts demo funding only.
- Generate a fresh `APP_KEY` per environment and keep it out of Git (rotating it disables existing 2FA enrolments).
- Back up `api/storage/documents/` together with the database.
- cPanel deploy: `npm run build` → `web/dist/*` (includes `.htaccess`, `sw.js`, manifest) to `public_html/`; expose `api/public/` as `public_html/api`
  with the rest of `api/` outside the web root; set up the daily cron; test backup restore.
