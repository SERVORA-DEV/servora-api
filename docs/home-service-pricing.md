# Home-service catalog pricing

Implemented on API `COLAII`, web `COLE`, and mobile `Nicolaii_Mobile`. No commits or deployment database changes were made.

## Behavior

- Business and branch settings no longer expose a flat fee per visit. New booking policies and home-service offers return a compatibility fee of zero. Existing appointment fee snapshots and historical earnings are retained.
- Each service has independent `In Spa` and `Home Service` variants. Home variants have their own UUID, duration, price, and branch availability/price override. Owners must configure home options before enabling home service; a facility-required service cannot be offered at home.
- The web service form keeps home options when home availability is turned off. Copy in-spa options is an explicit owner action, not automatic pricing.
- A package keeps the same included services and add-ons, with a separate home package price and each service's home duration. Its total home duration is derived from quantities and included add-ons. Optional home component values preserve the existing package allocation and commission behavior; they are not additional customer charges. The existing package commission calculation continues to use component values, as it does for in-spa packages.
- Package branch overrides have independent in-spa and home prices; resetting the home override uses the base home package price.
- The front desk and mobile booking flow select options for the chosen visit location. Changing location clears stale service/package/time/therapist selections. The API rejects an option for the wrong location and rejects unconfigured home packages.
- Standalone service earnings use the existing home-service commission policy on the home line price (including branch overrides). Commission policy and historical commission snapshots are unchanged.

## Database and rollout

Migration: `database/migrations/2026_11_10_160000_home_service_catalog_prices.php`.

1. Back up and verify the target database/environment through the normal deployment process.
2. Run the migration on the intended environment. It adds location-specific variants, package/component home fields, and package home branch overrides. The active variant uniqueness index includes location and excludes soft-deleted historical rows.
3. Deploy matching API, web, and mobile versions together. Older mobile versions submit in-spa option IDs for home visits; the updated API rejects those IDs instead of charging the wrong price. Require or communicate the mobile update before re-enabling client home bookings.
4. Have owners review home lengths, prices, branch availability, and package component values. Existing in-spa prices are not copied or marked reviewed automatically. Existing home-enabled service flags alone no longer make a service bookable at home when it has no home variants. Packages start with home availability off.
5. Smoke-test a standalone service and package in each location, branch overrides, travel buffers, minimum duration, checkout, and commission earnings on a staging PostgreSQL database.

The migration has not been run on the configured remote database. An isolated SQLite test covers its schema/data-preservation behavior. Rollback refuses when home variants exist, including historical ones; coordinate any rollback with client versions and reviewed data retention rather than deleting historical catalog rows.

## Validation

- Isolated API pricing, commission, home rules, package planning/allocation tests: 52 passed, 144 assertions.
- Mobile pricing and existing booking service/schedule tests: 30 passed. Targeted Dart analysis: no issues.
- Web suite: 129 passed, 6 existing administrator-photo tests fail because their test harness does not support `~/utils/ageLimits`. All four new pricing tests pass.
- Production web build passed. Web type checking reports 17 existing unrelated errors; no new pricing-component errors were reported.
- Browser checks used the actual service/package form components with local fixtures: independent home prices, toggle preservation, derived package home duration, desktop/narrow layouts, and existing color themes. No real customer data or remote writes were used.
- The existing home-service PostgreSQL feature test was attempted against explicit localhost `servora_test` settings and stopped because PostgreSQL at `127.0.0.1:5432` refused the connection. It did not reach test assertions. Full integration testing remains pending on a configured local/staging PostgreSQL database.

## Modified files

### servora-api

- `app/Http/Requests/Business/BranchAvailabilityRequest.php`
- `app/Http/Requests/Business/BranchOfferingsRequest.php`
- `app/Http/Requests/Business/BranchSettings/BranchBookingPolicyRequest.php`
- `app/Http/Requests/Business/CatalogRequestRequest.php`
- `app/Http/Requests/Business/PackageRequest.php`
- `app/Http/Requests/Business/ServiceRequest.php`
- `app/Http/Requests/Business/Settings/BookingDefaultsSettingsRequest.php`
- `app/Http/Resources/BranchDetailResource.php`
- `app/Http/Resources/LookupPackageResource.php`
- `app/Http/Resources/LookupServiceResource.php`
- `app/Http/Resources/NearbySpaResource.php`
- `app/Http/Resources/PackageResource.php`
- `app/Http/Resources/ServiceVariantResource.php`
- `app/Models/BranchPackage.php`
- `app/Models/Package.php`
- `app/Models/PackageServiceItem.php`
- `app/Models/ServiceVariant.php`
- `app/Repository/Business/BranchPackageRepository.php`
- `app/Repository/Business/ServiceVariantRepository.php`
- `app/Service/Business/AppointmentService.php`
- `app/Service/Business/BranchOfferingService.php`
- `app/Service/Business/HomeServiceRules.php`
- `app/Service/Business/PackageService.php`
- `app/Service/Business/ServiceExtensionService.php`
- `app/Service/Client/BookingPolicy.php`
- `database/migrations/2026_11_10_160000_home_service_catalog_prices.php`
- `docs/home-service-pricing.md`
- `tests/Feature/HomeServiceBookingTest.php`
- `tests/Unit/HomeCatalogPricingTest.php`

### servora-web

- `app/components/business/services/BranchOfferPanel.vue`
- `app/components/business/services/ManagerOfferings.vue`
- `app/components/business/services/PackageFields.vue`
- `app/components/business/services/PackageHomeFields.vue`
- `app/components/business/services/ServiceFields.vue`
- `app/components/business/settings/StaffSection.vue`
- `app/components/business/settings/branch/Booking.vue`
- `app/components/business/settings/company/Defaults.vue`
- `app/components/frontoffice/appointment-detail/AddToVisitPanel.vue`
- `app/components/frontoffice/booking/ServicePicker.vue`
- `app/composables/business/useBranchAvailability.ts`
- `app/composables/business/useOwnerPackages.ts`
- `app/composables/business/useOwnerServices.ts`
- `app/composables/business/useSettingsHub.ts`
- `app/composables/useFrontOfficeCatalog.ts`
- `app/pages/business/services/index.vue`
- `app/pages/frontoffice/appointments/new.vue`
- `app/utils/catalogForms.ts`
- `tests/home-catalog-pricing.test.ts`

### servora-mobile (pricing work only)

- `lib/data/app_state.dart`
- `lib/data/branch_detail_mapper.dart`
- `lib/models/branch_detail.dart`
- `lib/models/models.dart`
- `lib/screens/booking/booking_flow_screen.dart`
- `lib/screens/business/business_details_screen.dart`
- `test/home_catalog_pricing_test.dart`

Other pre-existing mobile edits were preserved.
