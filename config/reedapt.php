<?php

return [

    /*
    | Your API key, from Developers in the Reedapt dashboard. Keep it on the server.
    */
    'api_key' => env('REEDAPT_API_KEY'),

    /*
    | Leave as it is unless Reedapt tells you otherwise.
    */
    'base_url' => env('REEDAPT_BASE_URL', 'https://api.reedapt.com/v1'),

    /*
    | Seconds before a request is abandoned (default HTTP client only), and how
    | many times to retry one that failed in a way worth retrying.
    */
    'timeout' => (float) env('REEDAPT_TIMEOUT', 60),

    'max_retries' => (int) env('REEDAPT_MAX_RETRIES', 2),

    'webhook' => [

        /*
        | The whsec_ secret of your webhook endpoint, used by the
        | `reedapt.webhook` middleware.
        */
        'secret' => env('REEDAPT_WEBHOOK_SECRET'),

        /*
        | The oldest signature accepted, in seconds.
        */
        'tolerance' => (int) env('REEDAPT_WEBHOOK_TOLERANCE', 300),
    ],

];
