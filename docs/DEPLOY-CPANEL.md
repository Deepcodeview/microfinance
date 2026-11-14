# KBSAVE on cPanel — install or update

Domain: **kbsave.in**. One zip does both a **new install** and an **update**.
*Ek hi zip — naya install bhi, update bhi.*

---

## 1. Before you upload (first time only, 5 min)

**a) PHP** — cPanel → **Select PHP Version** → **8.2 or newer**, and tick **bcmath, pdo_mysql, mbstring, openssl**.

**b) Database** — cPanel → **MySQL® Databases**:
1. Create database `finserve` (cPanel names it `USER_finserve`).
2. Create user `finserve` with a strong password.
3. **Add user to database** → **ALL PRIVILEGES**.

Keep the database name, user and password ready.

**c) HTTPS** — cPanel → **SSL/TLS Status** → `kbsave.in` → **Run AutoSSL**.

---

## 2. Upload and extract (2 min)

cPanel → **File Manager**:

1. Settings (top right) → tick **Show Hidden Files** → Save.
2. Open the **website folder of kbsave.in** (its Document Root — the folder where the site's `index.html` lives).
3. **Upload** `kbsave-cpanel.zip` → right-click → **Extract** → **Overwrite** if asked.
4. Delete `kbsave-cpanel.zip`.

*Zip seedha website ke folder me extract karein — sab folders apne aap sahi jagah set ho jaayenge.*

---

## 3. Open the site — setup runs by itself (2 min)

Open **https://kbsave.in**. You are taken to **KBSAVE setup** automatically.

- **New install:** enter the database name, user and password, the company name, and **your own email and password**
  for the owner (Super Admin) account → **Install KBSAVE**.
- **Already installed before:** it says *Existing KBSAVE found* → **Update now**. All customers, loans, payments,
  settings, uploads and logins are kept.

Afterwards the setup page switches itself off (it only shows "Not found"); you may delete `kbsave-setup.php`.
(If you are not redirected, open `https://kbsave.in/kbsave-setup.php`.)

---

## 4. Cron jobs (3 min) — do not skip

The setup's last screen shows the first two exact commands for your server. Add them in cPanel → **Cron Jobs**:

| When | What it does |
|---|---|
| `15 0 * * *` | Daily: late fees, overdue / NPA status, savings interest, reminders |
| `*/5 * * * *` | Sends queued SMS (once an SMS provider is connected) |
| `30 1 * * *` | Daily database backup to `finserve/backups`, keeps 30 days (command below) |

Backup command (replace `USER` with your cPanel username, `DBUSER`/`DBNAME`/`DBPASS` with your database details):

```
30 1 * * * mysqldump --single-transaction -u DBUSER -p'DBPASS' DBNAME | gzip > /home/USER/public_html/finserve/backups/kbsave-$(date +\%F).sql.gz && find /home/USER/public_html/finserve/backups -name '*.sql.gz' -mtime +30 -delete
```

(Use the Document Root of kbsave.in in place of `/home/USER/public_html` if it is different.) Once a week, download the newest backup to your computer or Google Drive.

---

## 5. First login — set up the business

1. **Settings → Security → Single-owner approval → ON** while you are the only approver
   (otherwise nobody can approve your own loan applications).
2. **Accounting → Funds → Add capital** — your starting money. Then **Bank → branch cash** if loans will be paid in cash.
3. **Settings** → your **UPI ID**, branches, holidays. Turn on **two-factor sign-in**.
4. **Setup → Staff → Add staff** — managers, agents, finance (agents get a branch automatically).
5. Deposits/savings stay **off** until you have legal approval (Settings → Compliance gate).

---

## 6. Phones

Download the apps on each phone from:

- Agents: **https://kbsave.in/apps/KBSAVE-Agent.apk**
- Customers: **https://kbsave.in/apps/KBSAVE-Customer.apk**

They already connect to `https://kbsave.in`. Install over any older version.

**Customer app without an account:** anyone can open the KBSAVE app, see the loan and savings plans with a
calculator, and **register** with their mobile number. New registrations wait under **Applications → New
registrations**: check their KYC, then **Approve** (and assign an agent) or **Reject**. Customers can apply for a
loan or savings in the app; **Applications → Loan & savings requests → Accept** turns a loan request into a normal
loan application (approve → disburse) and opens the savings account directly.

---

## Updating later

Build a new zip (`./scripts/build-cpanel.sh`), extract it in the same folder with **Overwrite**, and open
the site. If the new version changes the database you are taken to **Database update needed**: enter your
**database password** (cPanel → MySQL® Databases) → **Update now**. Your `.env`, uploads, backups and data are
never in the zip, so they are never overwritten. If nothing in the database changed, the site simply opens.

---

## Check it is safe (1 min)

These must show an error page (403/404), never a download:

- `https://kbsave.in/finserve/api/.env`
- `https://kbsave.in/finserve/database/migrations/001_identity_access.sql`
- `https://kbsave.in/kbsave-setup.php` (after setup)
- `http://kbsave.in` must jump to **https://**

---

## Troubleshooting

| Problem | Fix |
|---|---|
| Setup shows ❌ for bcmath / pdo_mysql / PHP version | cPanel → Select PHP Version → fix → reload |
| "Could not connect to the database" | Check name/user/password; the user must be added to the database with ALL PRIVILEGES |
| Login page says "Cannot reach the server" | `.htaccess` files missing — extract again with **Show Hidden Files** on |
| `/api/v1/me` says NOT_INSTALLED | Setup was not finished — open `/kbsave-setup.php` |
| Files disappear after extracting / upload is blocked | The host's virus scanner (Imunify360) quarantined a file — cPanel → **Imunify360** → restore it / add to ignore list, or ask hosting support to whitelist the KBSAVE folder |
| Uploads fail | `finserve/api/storage` must be writable (755) |
| Overdue / late fees never change | The daily cron job is missing |
