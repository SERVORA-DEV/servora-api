# Subscriptions & Revenue report

The System Administrator Reports page (`/system/platform`) combines the previous Subscriptions and Revenue tabs into **Subscriptions & Revenue**. Other reports, the global TopBar, sidebar, theme, and existing table component remain in place. The detailed invoice list and payment-status filters remain in **Transactions**.

The combined report has a searchable Business selector, Date Range, Plan (including Free Trial), and Monthly/Yearly Billing Cycle filters. Apply Filters stages changes consistently across the figures, charts, summary, and exports. Reset and individual filter chips update the same dataset.

## Data and dates

- **Businesses on a plan** is a period-end snapshot, including free trials. A business UUID counts once, using its latest started term. Pending/unstarted checkout attempts are excluded. Future renewal terms do not hide the currently running term. Expiry, cancellation, and soft-deletion dates end a term's snapshot presence. An older trial cannot reappear after a subsequent paid term expires. This is a term report, not an access/entitlement calculation; grace-period access policy is unchanged.
- **Free-trial businesses** is the trial subset of that snapshot. Trial rows contribute no revenue.
- **Successful payments** counts recorded Paid/Refunded payments with `paid_at` in the selected dates, including successfully collected charges that were later refunded. Pending, Failed, Voided, deleted payments, deleted billings, and appointment billings are excluded.
- **Net payments collected** is actual payment amounts minus recorded `refunded_amount`, summed in integer cents. It includes the amounts collected for plans, extras, and tax. It is not projected recurring revenue, invoiced totals, profit, or tax-exclusive accounting revenue. Fully recorded refunds net to zero.
- A date range selects payment dates for collections and its end date for business snapshots. The start date does not exclude businesses whose still-running term began earlier. Future end dates stop at the refresh timestamp. Dates use the browser's local calendar, consistent with the report's date picker.
- Refund occurrence timestamps are not stored in the current payment model. Recorded refunds are attributed to the original payment period in the trend, figures, and summary, including refunds recorded after a selected historical end date. This is stated on the page; the chart does not claim to show cash refunded in each month.
- Completed checkout history supplies the purchased plan and billing cycle for payments, preserving attribution after in-place upgrades. Subscription snapshots replay completed subscribe/renew/auto-renew/upgrade plan changes. Archived plan versions remain readable. Legacy payments without checkout history fall back to their saved subscription plan; exact old plan attribution cannot be reconstructed. When the first available term event is an upgrade, earlier snapshots explicitly show an unknown historical plan instead of inventing a tier.

**Revenue Trend** and **Subscription Activity** share calendar periods, including explicit zero months. More than 24 months uses yearly buckets. Subscription Activity shows distinct paid-plan businesses and trial businesses at each period end; its last point is capped at the selected end/refresh timestamp. Plan Distribution uses the final snapshot with one plan per business.

**Business & Plan Summary** groups by business UUID, plan category, and billing cycle. A business that paid for different plans can therefore occupy multiple summary rows while remaining one business in the snapshot headline. Summary columns include successful payment count, paid amount, recorded refunds, and net collected. The on-screen limit remains 50 rows; exports include every matching row and the applied filters.

## API and deployment

`GET /api/system/reports/subscriptions?section=subscriptions|payments` is an additive, read-only report endpoint behind the existing authentication, system-administrator role, IP, two-factor, and `report_view` gates. It returns paginated data, default 100 and maximum 250 rows per request. The web client loads every page of both sections before publishing a refresh. Failed requests retain the previous complete result and offer retry. Gateway identifiers, checkout metadata, tokens, and payment credentials are not returned.

Deploy the API endpoint alongside the web change. No migrations, record updates, seeders, pricing, checkout, renewal, refunds, or entitlement-rule changes are required. Work stays on web `COLE` and API `COLAII`.

## Files changed for this merge

Web:

- `app/pages/system/platform.vue`
- `app/composables/system/useSystemReports.ts`
- `app/components/system/reports/Overview.vue`
- `app/utils/subscriptionReport.ts` (new)
- `tests/subscription-report.test.ts` (new)
- `tests/registration-report.test.ts` (test loader supports the new utility import)

API:

- `app/Http/Controllers/System/SubscriptionReportController.php` (new)
- `routes/api.php`
- `tests/Feature/SubscriptionReportTest.php` (new)
- `docs/subscription-revenue-report.md` (new)

## Verification

- All 194 web tests passed, including 10 new combined-report tests. Coverage includes trial-to-paid deduplication, renewals, full/partial refunds, UUID-based filters, historical upgrade attribution, local date boundaries, end-date snapshots, zero periods, yearly ranges, pagination/retry, staged/applied filters, the rendered page, and complete exports beyond 50 rows. Existing search selection and narrow/desktop comparison-chart geometry tests also passed.
- All 20 API report/primary-administrator regression tests passed (202 assertions), including 5 new subscription-report tests. These use isolated in-memory SQLite fixtures; application records are untouched.
- Both query sections ran successfully against the configured local PostgreSQL database inside a read-only transaction: 2 subscription terms and 1 confirmed payment, net collected ₱1,299.00.
- Type checking continues to report 17 pre-existing errors, including an existing optional chart-color type in the separate Transactions report. No errors were reported for the new utility, combined-report content, or page additions.
- Nuxt production build completed successfully (exit 0). Windows emitted dependency symlink permission warnings while assembling the generated server output; the build was not deployed.
- Authenticated browser inspection remains unverified because Computer Use could not reliably determine the Windows browser URL in this environment. Automated Vue rendering and chart geometry tests provide coverage but do not replace light/dark visual inspection.
