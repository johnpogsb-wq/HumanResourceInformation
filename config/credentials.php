<?php

/*
|--------------------------------------------------------------------------
| Credential expiry monitoring
|--------------------------------------------------------------------------
| How much warning each kind of 201-file document needs before it lapses.
| CredentialExpiryScanner reads everything here, so giving HR more lead time
| on a document is a config edit, not a code change.
*/

return [

    /*
    | Lead time for anything not named below.
    */
    'default_warning_days' => 30,

    /*
    | Renewal is not instant, and it is not equally slow for everything. An
    | LTO licence renewal wants weeks of notice; a training certificate can be
    | re-issued quickly. The window is per document type so the warning lands
    | while there is still time to act on it.
    */
    'warning_days' => [
        'drivers_license' => 60,
        'medical' => 45,
        'clearance' => 45,
        'certificate' => 30,
        'government_id' => 30,
        'contract' => 30,
    ],

    /*
    | Documents whose expiry legally stops the employee from doing the job —
    | a driver with a lapsed licence may not drive, and the liability is the
    | company's. These are flagged harder than an expired certificate.
    */
    'blocking_types' => [
        'drivers_license',
        'medical',
    ],
];
