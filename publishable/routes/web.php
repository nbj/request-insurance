<?php

use Illuminate\Support\Facades\Route;

Route::namespace('Nbj\RequestInsurance\Controllers')->prefix('vendor')->group(function () {
    Route::resource('request-insurances', 'RequestInsuranceController')
        ->only(['index', 'show', 'destroy'])
        ->middleware('web');

    Route::post('request-insurances/{request_insurance}/retry', [
        'uses' => 'RequestInsuranceController@retry',
        'as'   => 'request-insurances.retry',
    ])->middleware('web');

    Route::post('request-insurances/{request_insurance}/unlock', [
        'uses' => 'RequestInsuranceController@unlock',
        'as'   => 'request-insurances.unlock',
    ])->middleware('web');
});
