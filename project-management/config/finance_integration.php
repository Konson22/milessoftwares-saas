<?php

/*
|--------------------------------------------------------------------------
| Finance integration (Phase 3B, ADR-0017 §6)
|--------------------------------------------------------------------------
|
| Off everywhere until Finance handles finance.spending.* events and the
| platform issues Finance-to-Projects event tokens. While it is off, no
| organization can be switched to integrated (projects:finance-mode), so
| nothing is sent and every payment path stays as it is.
|
*/

return [
    'enabled' => (bool) env('PROJECTS_FINANCE_INTEGRATION', false),

    // The application allowed to report outcomes (the token's subject).
    'source' => 'finance',

    // A request Finance has not acknowledged after this long is flagged.
    'unacknowledged_hours' => 24,

    // Approved by Finance but unpaid after this long is flagged as delayed.
    'payment_delay_days' => 14,
];
