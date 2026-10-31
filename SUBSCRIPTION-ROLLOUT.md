# Subscription catalog rollout

This release implements Basic (1 branch / 3 staff logins), Premium (3 / 10), and Enterprise (15 / 30), with the existing PHP 499 / 999 / 1,999 monthly and PHP 4,990 / 9,990 / 19,990 yearly prices.

## Apply

Deploy the API changes and run the additive migration before enabling the updated web screens:

```powershell
php artisan migrate --path=database/migrations/2026_11_09_100000_add_subscription_entitlements.php
php artisan db:seed --class=SubscriptionPlanSeeder
```

The targeted seeder publishes the paid catalog through SubscriptionPlanService's normal versioning path. Plans with attached subscriptions are archived and replaced, rather than mutating purchased terms. Existing owners receive the normal plan-change notification; their purchased terms continue until renewal. The seeder does not replace an existing trial plan. Back up the database before a production rollout and run the existing application scheduler for automatic renewal/reminders.

Do not run `migrate:fresh`, the entire DatabaseSeeder, or a reset command against an application database to install this feature.

## Rules

- Additional user accounts cost PHP 49/month on every new paid tier. Enterprise additional branches cost PHP 199/month. Other tiers require a plan change for more branches.
- Extra quantities are chosen before purchasing; yearly terms collect twelve months of extra charges upfront. In-term increases follow the existing full-cycle price-difference rule and retain the current expiry; reductions are scheduled for renewal. A scheduled choice does not activate until payment.
- Purchased extras and base-plan payments are recorded separately on each subscription term. The new catalog's versioned unit prices determine its extras; historical plans retain their own terms.
- Basic includes mobile listing and 30 successfully created app bookings per business/calendar month in Asia/Manila. Cancelled/deleted bookings still count; rescheduling does not add another booking. Existing visits, billing, desk bookings and rescheduling remain available at the quota. Server booking creation locks the business before checking the count.
- Basic exports CSV from the most recent 30 calendar days, including today. Report viewing retains its normal date filters. Premium/Enterprise export PDF, Excel and CSV for any available date range. Restricted export data is fetched from an authorized, branch-scoped API endpoint.
- Packages and customer programs retain their existing plan checks. Listing, reviews, reports, and export controls now also consult the plan. Subscription access middleware applies to business staff as well as owners; role permissions remain separate requirements.
- Capacity enforcement counts only branches/logins that are not on plan hold. A move to a smaller plan uses the existing keep-choice workflow and retains historical records. The owner can keep additional capacity by buying eligible extras.
- Automatic renewal is attempted at/after expiry rather than early, so the next plan's features/holds do not activate before its paid term starts.

## Validation

Focused PHP tests (using disposable in-memory fixtures where needed):

```powershell
php vendor/phpunit/phpunit/phpunit tests/Unit --filter 'PlanTermsTest|PlanSwitchQuoteTest|PlanFitTest|SubscriberStateTest|SubscriptionReminderScheduleTest|XenditDemoOnlyTest'
```

Integration tests require a running isolated PostgreSQL `servora_test` database configured through phpunit.xml. The new test refuses to refresh another database:

```powershell
php vendor/phpunit/phpunit/phpunit tests/Feature/SubscriptionEntitlementsTest.php
```

The integration test covers payment completion idempotency, paid-extra capacity, base-price upgrade credit, scheduling capacity reductions, restricted export dates/formats and business isolation, and cancelled-mobile-booking quota consumption. PostgreSQL is required by existing historical migrations; SQLite cannot run the full migration chain. The focused unit suite separately exercises actual quote rules and the additive migration against a disposable in-memory fixture.

Implementation verification: the focused PHP suite passed 49 tests / 165 assertions, and the web Node suite passed 10 tests. The Nuxt production build passed with `NODE_OPTIONS=--max-old-space-size=4096`, and the built login page loaded in the browser. Global web type checking still reports 29 existing diagnostics in unchanged files. PostgreSQL integration tests and authenticated purchase/renewal browser flows remain unverified because no local PostgreSQL service was available. The application database migration and catalog publication have not been run.

Before release, exercise a new purchase, current-plan extra increase, reduction at renewal, tier/cycle change, failed/retried payment, renewal with extras, the 30th/31st simultaneous mobile bookings, month rollover, and manager/owner export scopes using gateway test mode. Keep production payments in the existing demo lock unless live charging is intentionally configured.
