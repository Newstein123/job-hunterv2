<?php

/*
|--------------------------------------------------------------------------
| Usage limits per user
|--------------------------------------------------------------------------
|
| Caps for the testing period, to bound AI cost and sending volume. Set a
| limit to 0 to remove it. Per-account daily sending limits still apply.
|
*/

return [
    // AI-drafted applications: every "Draft application" and "Regenerate" uses one.
    'drafts_per_user' => (int) env('LIMIT_DRAFTS_PER_USER', 300),
    // Application emails sent (replies to employers don't count).
    'sends_per_user' => (int) env('LIMIT_SENDS_PER_USER', 1000),
];
