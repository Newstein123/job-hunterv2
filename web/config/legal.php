<?php

/*
|--------------------------------------------------------------------------
| Legal pages
|--------------------------------------------------------------------------
|
| Who runs this JobHunter instance and how to reach them, shown on the
| Privacy Policy and Terms of Service pages. Bump `updated` whenever the
| wording of either page changes.
|
*/

return [
    'operator' => env('LEGAL_OPERATOR', env('APP_NAME', 'JobHunter')),
    'contact_email' => env('LEGAL_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS')),
    // Optional, e.g. "Singapore". The Terms only include a governing-law clause when this is set.
    'jurisdiction' => env('LEGAL_JURISDICTION'),
    'updated' => '2026-10-26',
];
