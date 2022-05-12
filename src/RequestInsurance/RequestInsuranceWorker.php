<?php

namespace Cego\RequestInsurance;

use Closure;
use Exception;
use Throwable;
use Carbon\Carbon;
use Nbj\Stopwatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cego\RequestInsurance\Enums\State;
use Cego\RequestInsurance\Models\RequestInsurance;

class RequestInsuranceWorker
{
    /**
     * Holds a hash identifier for the service instance once set
     *
     * @var string|null $runningHash
     */
    protected ?string $runningHash = null;

    /**
     * Once set, holds the number of microseconds to wait between each cycle
     *
     * @var int $microSecondsToWait
     */
    protected int $microSecondsToWait;

    /**
     * Boolean flag, used to indicate if the service has received an outside signal to shutdown processing of records.
     * This allows for graceful shutdown, instead of shutting down the service hard - Causing unwanted states in Request Insurance rows.
     *
     * @var bool
     */
    protected bool $shutdownSignalReceived = false;

    /**
     * The number of request insurances each worker processes pr. epoch
     *
     * @var int
     */
    protected int $batchSize;

    /**
     * Timestamp used for running stuff at most once every second
     *
     * @var array
     */
    protected array $secondIntervalTimestamp;

    /**
     * RequestInsuranceService constructor.
     */
    public function __construct(int $batchSize = 100, int $microSecondsToWait = 100000)
    {
        $this->microSecondsToWait = $microSecondsToWait;
        $this->batchSize = $batchSize;
        $this->runningHash = Str::random(8);
        $this->secondIntervalTimestamp = hrtime();
        Log::withContext(['worker.id' => $this->runningHash]);
    }

    /**
     * Runs the service
     *
     * @param false $runOnlyOnce
     *
     * @throws Throwable
     */
    public function run(bool $runOnlyOnce = false): void
    {
        Log::info(sprintf('RequestInsurance Worker (#%s) has started', $this->runningHash));

        $this->setupShutdownSignalHandler();

        do {
            try {
                if (env('REQUEST_INSURANCE_WORKER_USE_DB_RECONNECT', true)) {
                    DB::reconnect();
                }

                $executionTime = Stopwatch::time(function () {
                    $this->processRequestInsurances();
                    $this->atMostOnceEverySecond(fn () => $this->readyWaitingRequestInsurances());
                });

                $waitTime = (int) max($this->microSecondsToWait - $executionTime->microseconds(), 0);

                usleep($waitTime);
            } catch (Throwable $throwable) {
                Log::error($throwable);
                sleep(5); // Sleep to avoid spamming the log
            }

            pcntl_signal_dispatch();
        } while ( ! $runOnlyOnce && ! $this->shutdownSignalReceived);

        Log::info(sprintf('RequestInsurance Worker (#%s) has gracefully stopped', $this->runningHash));
    }

    /**
     * Sets up signal handler to make sure that request insurance can shutdown gracefully.
     *
     * This is required to avoid shutting request insurance workers down while they are still processing requests.
     * A force shutdown tends to put requests in a limbo state, where they are locked and never unlocked again.
     */
    protected function setupShutdownSignalHandler(): void
    {
        pcntl_signal(SIGQUIT, [$this, 'sig_handler']); // Code 3
        pcntl_signal(SIGTERM, [$this, 'sig_handler']); // Code 15
    }

    /**
     * The shutdown signal handler method responsible to stop further processing of rows.
     *
     * @param int $signo
     * @param mixed $siginfo
     */
    public function sig_handler(int $signo, $siginfo): void
    {
        Log::info(sprintf('RequestInsurance Worker (#%s) received a shutdown signal - Beginning graceful shutdown', $this->runningHash));

        $this->shutdownSignalReceived = true;
    }

    /**
     * Method for running the given closure at most once every second.
     * This method cannot be reused multiple time.
     *
     * @param Closure $closure
     *
     * @return void
     */
    protected function atMostOnceEverySecond(Closure $closure): void
    {
        // $now[0 => seconds, 1 => nanoseconds]
        $now = hrtime();

        // If a second has passed
        if ($this->secondIntervalTimestamp[0] < $now[0]) {
            $this->secondIntervalTimestamp = $now;
            $closure();
        }
    }

    /**
     * Marks waiting request insurances as ready
     *
     * @return void
     */
    protected function readyWaitingRequestInsurances(): void
    {
        RequestInsurance::query()
            ->where('state', State::WAITING)
            ->where('retry_at', '<=', Carbon::now())
            ->update(['state' => State::READY, 'state_changed_at' => Carbon::now(), 'retry_at' => null]);
    }

    /**
     * Processes all requests ready to be processed
     *
     * @throws Throwable
     */
    protected function processRequestInsurances(): void
    {
        /** @var Collection $requestIds */
        $requestIds = DB::transaction(function () {
            try {
                $measurement = Stopwatch::time(function () {
                    return $this->acquireLockOnRowsToProcess();
                });

                if ($measurement->seconds() >= 80) {
                    Log::critical(sprintf('%s: Selecting RI rows for processing took %d seconds!', __METHOD__, $measurement->seconds()));
                } elseif ($measurement->seconds() >= 60) {
                    Log::warning(sprintf('%s: Selecting RI rows for processing took %d seconds!', __METHOD__, $measurement->seconds()));
                } elseif ($measurement->seconds() >= 30) {
                    Log::info(sprintf('%s: Selecting RI rows for processing took %d seconds!', __METHOD__, $measurement->seconds()));
                }

                return $measurement->result();
            } catch (Throwable $throwable) {
                Log::error($throwable);

                throw $throwable;
            }
        }, 5);

        // Gets requests to process ordered by priority
        $requests = resolve(RequestInsurance::class)::query()
            ->whereIn('id', $requestIds)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $requests->each(function ($request) {
            /** @var RequestInsurance $request */
            try {
                $request->process();
            } catch (Throwable $throwable) {
                Log::error($throwable);

                $request->pause();
            } finally {
                $request->unlock();
            }
        });
    }

    /**
     * Acquires a lock on the next rows to process, by setting the locked_at column
     *
     * @throws Exception
     *
     * @return Collection
     */
    public function acquireLockOnRowsToProcess(): Collection
    {
        $requestIds = $this->getIdsOfReadyRequests();

        // Bail if no request are ready to be processed
        if ($requestIds->isEmpty()) {
            return $requestIds;
        }

        $now = CarbonImmutable::now();

        $locksWereObtained = resolve(RequestInsurance::class)::query()
            ->whereIn('id', $requestIds)
            ->update([
                'state'            => State::PENDING,
                'state_changed_at' => $now,
                'locked_at'        => $now,
                'updated_at'       => $now,
            ]);

        if ( ! $locksWereObtained) {
            throw new Exception(sprintf('RequestInsurance failed to obtain lock on ids: [%s]', $requestIds->implode(',')));
        }

        return $requestIds;
    }

    /**
     * Gets a collection of RequestInsurances ready to be processed
     *
     * @return mixed
     */
    public function getIdsOfReadyRequests()
    {
        return resolve(RequestInsurance::class)::query()
            ->select('id')
            ->readyToBeProcessed()
            ->take($this->batchSize)
            ->lockForUpdate()
            ->pluck('id');
    }
}
