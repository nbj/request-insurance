<?php

namespace Cego\RequestInsurance\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class RequestInsuranceLog
 *
 * @property int $id
 * @property string|array $response_headers
 * @property string $response_body
 * @property int $response_code
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RequestInsuranceLog extends SaveRetryingModel
{
    /**
     * Indicates if all mass assignment is enabled.
     *
     * @var bool
     */
    protected static $unguarded = true;

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable(): string
    {
        // Use the one defined in the config, or the whatever is default
        return Config::get('request-insurance.table_logs') ?? parent::getTable();
    }

    /**
     * Perform any actions required after the model boots.
     *
     * @return void
     */
    protected static function booted()
    {
        // We need to hook into the saving event to manipulate data before it is stored in the database
        static::saving(function (RequestInsuranceLog $request) {
            // We make sure to json encode response headers to json if passed as an array
            if (is_array($request->response_headers)) {
                $request->response_headers = json_encode($request->response_headers, JSON_THROW_ON_ERROR);
            }
        });
    }

    /**
     * Relationship with RequestInsurance
     *
     * @return BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(get_class(resolve(RequestInsurance::class)), 'request_insurance_id');
    }
}
