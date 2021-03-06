<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Request Insurance
    |--------------------------------------------------------------------------
    |
    | Here you can enable RequestInsurance or disable it. Once enabled the
    | RequestInsuranceService command can start processing requests
    |
    */

    'enabled' => env('REQUEST_INSURANCE_ENABLED', true),


    /*
    | Sets if keep alive should be sent with curl requests
    */

    'keepAlive' => true,


    /*
    | Sets the timeout for a curl request, this is the time execute() has to complete the requests
    */

    'timeoutInSeconds' => 5,


    /*
    | Set the amount of microseconds to wait between each run cycle
    */

    'microSecondsToWait' => 2000000,


    /*
    | Set the maximum number of retires before backing off completely
    */

    'maximumNumberOfRetries' => 10,


    /*
    | Set the number of requests in each batch
    */

    'batchSize' => env('REQUEST_INSURANCE_BATCH_SIZE', 100),


    /*
     | Set the concrete implementation for HttpRequest
     */

    'httpRequestClass' => env('REQUEST_INSURANCE_HTTP_REQUEST_CLASS', \Nbj\RequestInsurance\CurlRequest::class)
];
