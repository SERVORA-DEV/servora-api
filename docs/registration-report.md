# Combined Business Registrations report

The System Administrator Reports page uses one **Business Registrations** tab with the subtitle **Business and branch registration activity**. Business and branch entries remain separate records and separate chart series (forest green and gold).

The report reads `GET /api/system/reports/registrations`, protected by the existing administrator role, IP, two-factor, and `report_view` gates. This is an additive read-only endpoint; the pending-review queue, business/branch management endpoints, approvals, and database schema are unchanged.

The endpoint includes pending, verified, rejected, and suspended records. Unsubmitted drafts and soft-deleted businesses/branches are excluded. Previously submitted records awaiting resubmission remain included. Each business/branch appears once, regardless of repeated submissions or the number of branches it owns.

Registration date is the earliest `Submit` audit event for that specific record. Legacy entries without submission history use `created_at`, explicitly identified as **Record created** in the table and exports. Mutable `updated_at` and approval dates are never substituted for submission dates. The UI explains the date basis; exact historical submission dates cannot be recovered where no history exists.

The client loads every API page before publishing refreshed data. Date, business UUID, registration type, and current verification status filter the entire report: overview figures, trend, status comparison, type distribution, table, and exports. Trend buckets include zero-count months; ranges longer than 24 months use year buckets. The on-screen table retains the existing 50-row limit, while exports include all matching rows. Failed refreshes show an error and retain the previous complete result for retry.

## Changed files

API (`COLAII`):
- `app/Http/Controllers/System/RegistrationReportController.php`
- `routes/api.php`
- `tests/Feature/RegistrationReportTest.php`
- `docs/registration-report.md`

Web (`COLE`):
- `app/composables/system/useSystemReports.ts`
- `app/utils/registrationReport.ts`
- `app/pages/system/platform.vue`
- `app/components/system/reports/ChartCard.vue`
- `app/components/system/reports/StackedBarChart.vue`
- `app/components/system/reports/Overview.vue`
- `tests/registration-report.test.ts`

No migrations or record updates are required. Deploy the API endpoint with the web changes. Subscriptions, Revenue, Transactions, and the other report types retain their existing behavior.

## Verification results

- 179 web tests passed, including filter synchronization, calendar boundaries, pagination failure/retry, full export, grouped bars at narrow/desktop widths, and unchanged stacked chart geometry.
- 15 API tests passed (registration reporting plus primary-administrator regression tests). The new route requires authentication, the administrator role, and `report_view`.
- The read-only PostgreSQL check returned one business and two branches, all with recorded submission dates. No schema or record changes were performed for this report.
- Nuxt production build completed. Windows emitted dependency symlink permission warnings while assembling the generated server output; generated output was not deployed.
- Type checking still reports the same 17 existing errors. The separate permission-coverage suite has an existing failure for `PUT /api/business/setup-guide`, which this change does not modify.
- Authenticated visual inspection was stopped because Computer Use could not reliably determine the Windows browser URL. Automated rendering and geometry checks passed; actual light/dark browser inspection remains unverified.
