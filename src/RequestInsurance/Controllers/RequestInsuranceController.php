<?php

namespace Cego\RequestInsurance\Controllers;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Factory;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Cego\RequestInsurance\Enums\State;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Cego\RequestInsurance\Models\RequestInsurance;
use Illuminate\Contracts\Filesystem\FileNotFoundException;

class RequestInsuranceController extends Controller
{
    /**
     * Frontend view for displaying and index of RequestLogs
     *
     * @param Request $request
     *
     * @throws Exception
     *
     * @return View|Factory
     */
    public function index(Request $request)
    {
        // Flash the request parameters, so we can redisplay the same filter parameters.
        $request->flash();

        $paginator = RequestInsurance::query()
            ->orderByDesc('id')
            ->filteredByRequest($request)
            ->paginate(25);

        return view('request-insurance::index')->with([
            'requestInsurances' => $paginator,
        ]);
    }

    /**
     * Shows a specific request insurance
     *
     * @param RequestInsurance $requestInsurance
     *
     * @return View|Factory
     */
    public function show(RequestInsurance $requestInsurance)
    {
        $requestInsurance->load('logs');

        return view('request-insurance::show')->with(['requestInsurance' => $requestInsurance]);
    }

    /**
     * Abandons a request insurance
     *
     * @param RequestInsurance $requestInsurance
     *
     * @return mixed
     */
    public function destroy(RequestInsurance $requestInsurance)
    {
        $requestInsurance->abandon();

        return redirect()->back();
    }

    public function edit(RequestInsurance $requestInsurance)
    {
        // Only allow updates for requests that have not completed or been abandoned
        if ($requestInsurance->isCompleted() || $requestInsurance->isAbandoned()){
            return redirect()->back();//TODO more error handling?
        }

        // request data
        $payload = [
            'justification' => 'Draft: Request Insurance needs editing (Unedited)',
            'query' => $this->updateRequestInsuranceSQLQuery($requestInsurance),
            'required_number_of_approvals' => 1,
            'connection_id' => '',
            'user' => '',
        ];
        $url = Config::get('request-insurance.edit_endpoint');
        $response = Http::post($url, $payload);
        // TODO decode response?
        if ($response->success){
            if (empty($query = $response->query)){
                return redirect()->back();//TODO more error handling?
            }

            $viewEndpoint = sprintf(Config::get('request-insurance.view_endpoint'), $query->id);
            return redirect()->away($viewEndpoint);
        }

        return redirect()->back();//TODO more error handling?
    }

    private function updateRequestInsuranceSQLQuery(RequestInsurance $requestInsurance) : string
    {
        return sprintf(
            "
            UPDATE %s\n
            SET\n
            'url' = %s,\n
            'method' = %s,\n
            'headers' = %s,\n
            'payload' = %s\n
            WHERE id = %s",
            $requestInsurance->getTable(),
            $requestInsurance->url,
            $requestInsurance->headers,
            $requestInsurance->headers,
            $requestInsurance->payload,
            $requestInsurance->id);
    }

    /**
     * Retries a request insurance
     *
     * @param RequestInsurance $requestInsurance
     *
     * @return mixed
     */
    public function retry(RequestInsurance $requestInsurance)
    {
        $requestInsurance->retryNow();

        return redirect()->back();
    }

    /**
     * Unlocks a request insurance
     *
     * @param RequestInsurance $requestInsurance
     *
     * @return mixed
     */
    public function unlock(RequestInsurance $requestInsurance)
    {
        $requestInsurance->unstuckPending();

        return redirect()->back();
    }

    /**
     * Gets json representation of service load
     *
     * @return array
     */
    public function load()
    {
        $files = Storage::disk('local')->files('load-statistics');

        $loadFiveMinutes = 0;
        $loadTenMinutes = 0;
        $loadFifteenMinutes = 0;

        foreach ($files as $file) {
            try {
                $loadStatistics = json_decode(Storage::disk('local')->get($file));

                $loadFiveMinutes += $loadStatistics->loadFiveMinutes;
                $loadTenMinutes += $loadStatistics->loadTenMinutes;
                $loadFifteenMinutes += $loadStatistics->loadFifteenMinutes;
            } catch (FileNotFoundException $exception) {
                // Ignore for now
            }
        }

        if (Config::get('request-insurance.condenseLoad')) {
            $numberOfFiles = count($files);

            $loadFiveMinutes = $loadFiveMinutes / $numberOfFiles;
            $loadTenMinutes = $loadTenMinutes / $numberOfFiles;
            $loadFifteenMinutes = $loadFifteenMinutes / $numberOfFiles;
        }

        return [
            'loadFiveMinutes'    => $loadFiveMinutes,
            'loadTenMinutes'     => $loadTenMinutes,
            'loadFifteenMinutes' => $loadFifteenMinutes,
        ];
    }

    /**
     * Gets json representation of failed and active requests
     *
     * @return array
     */
    public function monitor()
    {
        return [
            'activeCount' => RequestInsurance::query()->where('state', State::READY)->count(),
            'failCount'   => RequestInsurance::query()->where('state', State::FAILED)->count(),
        ];
    }

    /**
     * Gets a collection of segmented number of requests
     *
     * @return \Illuminate\Support\Collection
     */
    public function monitor_segmented()
    {
        $stateCounts = DB::query()
            ->from(RequestInsurance::make()->getTable())
            ->selectRaw('state as state, COUNT(*) as count')
            ->groupBy('state')
            ->get()
            ->mapWithKeys(fn (object $row) => [$row->state => $row->count]);

        // Add default value of 0
        return collect(State::getAll())->map(fn () => 0)->merge($stateCounts);
    }
}
