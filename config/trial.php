<?php

// The free trial an owner can start once, from the Plans page, instead of
// paying straight away (SubscriptionService::startTrial). It is an ordinary
// subscription row flagged is_trial: no invoice, no grace period after it
// ends, and paying for any plan replaces it.
//
// servora-web advertises the same length on the landing page — keep
// app/utils/trial.ts there in step with `days`.
return [
    'enabled' => (bool) env('TRIAL_ENABLED', true),

    'days' => (int) env('TRIAL_DAYS', 14),

    // The plan tier a trial runs on. "Trial" is the dedicated Free Trial plan
    // (admin-editable on the Subscription Plans page, never sold); pointing
    // this at a paid tier makes the trial borrow that plan instead.
    'plan_category' => env('TRIAL_PLAN_CATEGORY', 'Trial'),
];
