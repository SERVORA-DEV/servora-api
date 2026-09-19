<?php

/*
|--------------------------------------------------------------------------
| Service categories
|--------------------------------------------------------------------------
|
| The service-area taxonomy a Service is classified under. Deliberately the
| same eleven values as facilities.category (see
| 2026_08_29_090000_add_category_and_remove_capacity_from_facilities_table),
| so a treatment and the room it's performed in speak one vocabulary and can
| be matched to each other later.
|
| Kept here rather than as a DB enum: an enum column on PostgreSQL is a
| varchar behind a CHECK constraint, so widening one means a migration that
| drops and re-adds that constraint (see App\Support\PostgresSchema, and
| the facilities.category column it maintains). services.category is a plain
| string validated with Rule::in(config('service_categories')) instead, so
| adding a category is a one-line change here with no migration at all.
|
| Mirrored on the web app in app/utils/service-categories.ts — keep the two
| in step.
|
*/

return [
    'Massage',
    'Facial',
    'Body Treatment',
    'Hair',
    'Nails',
    'Waxing',
    'Lash & Brow',
    'Makeup',
    'Couples',
    'VIP',
    'Wellness',
];
