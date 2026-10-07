<?php

return [

    /*
    | Maximum allowed difference (in seconds) between the signed timestamp and
    | the server time. Protects against replay of captured requests.
    */
    'tolerance' => (int) env('WEBHOOK_TOLERANCE', 300),

    /*
    | Events that are still in "received" status after this many minutes were
    | stored but never queued (for example the queue was down).
    | `webhooks:redispatch` queues them again.
    */
    'redispatch_after' => (int) env('WEBHOOK_REDISPATCH_AFTER', 5),

    /*
    | Known providers. Every provider may have several secrets at once, which
    | makes secret rotation possible without downtime. Secrets are read from
    | a comma separated environment variable.
    */
    'providers' => [
        'demo' => [
            'secrets' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('WEBHOOK_DEMO_SECRETS', '')),
            ))),
        ],
    ],

];
