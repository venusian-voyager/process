<?php

namespace Voyager\Process;

use Countable;
use Throwable;
use Voyager\Vessel\ControlPanel;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\NutsAndBolts\Collection;

class InvokedProcessPool implements Countable
{
    /**
     * The array of invoked processes.
     *
     * @var array
     */
    protected $invokedProcesses;

    /**
     * Create a new invoked process pool.
     *
     * @param  array  $invokedProcesses
     */
    public function __construct(array $invokedProcesses)
    {
        $this->invokedProcesses = $invokedProcesses;
    }

    /**
     * Send a signal to each running process in the pool, returning the processes that were signalled.
     *
     * @param  int  $signal
     * @return \Voyager\NutsAndBolts\Collection
     */
    public function signal(int $signal)
    {
        return $this->running()->each->signal($signal);
    }

    /**
     * Stop all processes that are still running.
     *
     * @param  float  $timeout
     * @param  int|null  $signal
     * @return \Voyager\NutsAndBolts\Collection
     */
    public function stop(float $timeout = 10, ?int $signal = null)
    {
        return $this->running()->each->stop($timeout, $signal);
    }

    /**
     * Get the processes in the pool that are still currently running.
     *
     * @return \Voyager\NutsAndBolts\Collection
     */
    public function running()
    {
        return (new Collection($this->invokedProcesses))->filter->running()->values();
    }

    /**
     * Wait for the processes to finish.
     *
     * @return \Voyager\Process\ProcessPoolResults
     */
    public function wait()
    {
        return new ProcessPoolResults((new Collection($this->invokedProcesses))->map->wait()->all());
    }

    /**
     * Wait for the processes to finish without blocking the loop.
     *
     * @return \Voyager\Contracts\IOPools\Promise  the ProcessPoolResults, keyed as the pool was, or the first failure to wait
     */
    public function waitAsync(): Promise
    {
        $all = ControlPanel::getInstance()->get(Loop::class)->promise();
        $results = array_fill_keys(array_keys($this->invokedProcesses), null);
        $left = count($this->invokedProcesses);

        if ($left === 0) {
            $all->resolve(new ProcessPoolResults([]));

            return $all;
        }

        foreach ($this->invokedProcesses as $key => $process) {
            $process->waitAsync()->then(
                function (mixed $result) use ($key, $all, &$results, &$left): mixed {
                    $results[$key] = $result;

                    if (--$left === 0) {
                        $all->resolve(new ProcessPoolResults($results));
                    }

                    return $result;
                },
                function (Throwable $e) use ($all): null {
                    if (! $all->settled()) {
                        $all->reject($e);
                    }

                    return null;
                },
            );
        }

        return $all;
    }

    /**
     * Get the total number of processes.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->invokedProcesses);
    }
}
