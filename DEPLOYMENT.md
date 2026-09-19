# Deploying servora-api

Supabase Postgres for data, Cloudinary for every image and document, Render
for the app itself. Nothing is ever written to the container's own disk.

---

## 1. Supabase

Create the project, then open **Dashboard → Connect** and copy the
**Session pooler** details.

Three things about that screen matter, and getting any of them wrong fails in
a way that looks like a generic connection timeout:

| | Use | Not |
|---|---|---|
| Host | `aws-0-<region>.pooler.supabase.com` | `db.<ref>.supabase.co` — IPv6-only without the paid IPv4 add-on, and Render egresses on IPv4 |
| Port | `5432` (session mode) | `6543` (transaction mode) — no prepared-statement support, which PDO's pgsql driver uses by default |
| User | `postgres.<project-ref>` | `postgres` — the pooler requires the dotted form |

Supabase's linter will flag every table as "RLS disabled". That is correct
here: Laravel connects as the `postgres` role and does all authorization
itself, and no Supabase client key is ever handed to a frontend.

## 2. Render

Import `render.yaml` (**New → Blueprint**) and fill in every value Render
prompts for. `.env.render.example` documents each one and where it comes from.

`docker/entrypoint.sh` runs on every boot and does, in order:

1. binds Apache to `$PORT` on `0.0.0.0`
2. `config:cache`, `route:cache`, `event:cache`
3. **`php artisan migrate --force`**
4. `apache2-foreground`

It runs `migrate`, never `migrate:fresh`. Seeding is deliberately not in
there — see below.

## 3. Seed once, by hand

After the first successful deploy, from **Render → your service → Shell**:

```
php artisan db:seed --force
php artisan db:seed --class=SubscriptionPlanSeeder --force
```

`DatabaseSeeder` covers the admin user plus `FacilitySeeder`,
`ServicePackageSeeder` and `StaffSeeder`. Run these **once**. They are not
idempotent, and they must never go into the entrypoint.

## 4. Verify

```
curl https://<service>.onrender.com/up
curl "https://<service>.onrender.com/api/spas/nearby?lat=14.6&lng=121.0&radius=10"
```

Then redeploy once and confirm the data survived — that is the check that
matters, since this repo previously ran `migrate:fresh --seed` on every boot.

---

## Email (Gmail SMTP)

All three mail flows — the client registration OTP, the password-reset OTP,
and the business-owner verification link — go out over Gmail SMTP.

Setup on the sending account:

1. Turn on **2-Step Verification**. App Passwords aren't offered without it.
2. Generate a 16-character App Password at
   [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
   and paste it **without** the spaces Google displays.
3. Set `MAIL_FROM_ADDRESS` to the same address as `MAIL_USERNAME`. Gmail
   rewrites the `From:` header to the authenticated account unless the address
   is a verified "Send mail as" alias, so anything else is silently discarded.

Free Gmail accounts cap at **500 messages/day**. Past that, sends fail and are
logged; the app stays up and users can retry via the resend endpoints.

Check the transport without touching the app:

```bash
php artisan tinker --execute="Mail::raw('check', fn(\$m) => \$m->to('you@gmail.com')->subject('Servora SMTP check')); echo 'sent';"
```

`Username and Password not accepted` means 2-Step Verification is off, or the
App Password still has spaces in it.

**If you ever deploy to Render:** its free tier blocks outbound SMTP on ports
25, 465 and 587, so Gmail SMTP cannot deliver from a free web service. That
needs a paid instance, or switching to an HTTP-API provider (Resend, Mailgun,
Postmark) whose traffic isn't blocked.

Mail failures never break a request. `UserService::deliver()` catches them,
logs the recipient and error (never the OTP), and the caller decides: signup
still returns `201` with a "use Resend" message, while an explicit resend
returns `503` rather than claiming success.

---

## Migration commands

| | |
|---|---|
| `php artisan migrate` | Apply pending migrations. What production runs (with `--force`). |
| `php artisan migrate --pretend` | Print the SQL without executing it. Only partly useful here — migrations that branch on a query result (`PostgresSchema`, the data backfills) see empty results and print an incomplete plan. |
| `php artisan migrate:status` | Which migrations have run. |
| `php artisan migrate:fresh --seed` | **Drops every table**, re-migrates, re-seeds. Local only. |
| `php artisan migrate:rollback` | Undo the last batch. `down()` is best-effort in several migrations — read the file first. |

## Local development

The app is Postgres-only — several migrations emit Postgres-specific DDL, so
MySQL and SQLite no longer work:

```
createdb servora
createdb servora_test
php artisan migrate:fresh --seed
php artisan test
```

`php.ini` needs `extension=pdo_pgsql` enabled. Test DB settings live in
`phpunit.xml`, not `.env`.

## Things that will bite you

- **Enum columns.** On Postgres, `$table->enum()` is a `varchar` behind a
  CHECK constraint, and `->enum()->change()` generates invalid SQL. Widen one
  through `App\Support\PostgresSchema::redefineEnum()` instead.
- **`LIKE` is case-sensitive.** Use `whereLike()` / `orWhereLike()`, which
  default to case-insensitive and compile to `ilike`.
- **Select aliases don't resolve in `WHERE`/`HAVING`** — only in `GROUP BY`
  and `ORDER BY`. Wrap the query in `fromSub()` instead (see
  `SpaBranchRepository::nearby`).
- **`->after()` is silently ignored**, so column order differs from the old
  MySQL database. Harmless.
- **Rotate any credential that was ever committed.** `.env.example` carried
  live-looking Cloudinary and Xendit values and an `APP_KEY` before this
  migration; assume all of them are public.
