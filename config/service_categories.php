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
| Kept here rather than as a DB enum: the facilities column is a raw MySQL
| "ALTER TABLE ... MODIFY ... ENUM", which is exactly why several existing
| migrations can't run against the SQLite test suite. services.category is a
| plain string validated with Rule::in(config('service_categories')), so
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
