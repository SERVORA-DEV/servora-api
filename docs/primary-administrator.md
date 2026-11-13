# Primary administrator protection

The first system administrator created during database setup is the primary administrator. It always receives the complete `system_administrator` permission bundle, including newly added permissions.

`users.is_primary_admin` stores this identity permanently. It is not mass assignable or accepted by administrator forms. A partial unique index permits at most one primary administrator. Names, emails, creation-date edits, and list sorting do not transfer protection.

## Rollout

Apply `2026_11_19_090000_protect_primary_administrator.php` with the normal API migration step before serving the updated application. On existing installations it marks the system administrator with the lowest user ID; it does not rename accounts, reset passwords, or alter account statuses. On a fresh database, `DatabaseSeeder` marks the initial account. Reseeding retains the marked account even after an email change.

The web Administrators list displays **Primary Administrator**, **Full access**, **Fixed permissions**, and **Protected**. It offers no edit, suspend, reactivate, or deactivate actions for this account. The API also rejects these management operations from other administrators, including those with every permission.

The primary administrator may still update their own personal details through Settings → Profile, and use the existing self-service photo and security endpoints. Their permissions and account status cannot be changed through account management, including by themselves. Other administrators retain the existing permission checks and last-account-manager safeguards.

## Verification

`tests/Feature/PrimaryAdministratorTest.php` uses an isolated SQLite in-memory schema and the real HTTP management routes. It covers migration selection, the unique marker, fresh/repeated seeding, immutable access and status, direct API edits, retained tokens after rejected deactivation, own-profile edits, untrusted marker fields, and normal administrator management. It never migrates or writes the configured application database.

The web regression tests in `tests/admin-photos.test.ts` cover the protected row/menu and blocked status mutation, alongside existing account and photo behavior. PostgreSQL deployment and authenticated browser verification should confirm the same behavior after rollout.
