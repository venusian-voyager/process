<?php

namespace Voyager\Process;

use Throwable;
use Voyager\Vessel\ControlPanel;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\NutsAndBolts\Collection;
use InvalidArgumentException;

/**
 * @mixin \Voyager\Process\Factory
 * @mixin \Voyager\Process\PendingProcess
 */
class Pipe
{
    /**
     * The process factory instance.
     *
     * @var \Voyager\Process\Factory
     */
    protected $factory;

    /**
     * The callback that resolves the pending processes.
     *
     * @var callable
     */
    protected $callback;

    /**
     * The array of pending processes.
     *
     * @var array
     */
    protected $pendingProcesses = [];

    /**
     * Create a new series of piped processes.
     *
     * @param  \Voyager\Process\Factory  $factory
     * @param  callable  $callback
     */
    public function __construct(Factory $factory, callable $callback)
    {
        $this->factory = $factory;
        $this->callback = $callback;
    }

    /**
     * Add a process to the pipe with a key.
     *
     * @param  string  $key
     * @return \Voyager\Process\PendingProcess
     */
    public function as(string $key)
    {
        return tap($this->factory->newPendingProcess(), function ($pendingProcess) use ($key) {
            $this->pendingProcesses[$key] = $pendingProcess;
        });
    }

    /**
     * Runs the processes in the pipe.
     *
     * @param  callable|null  $output
     * @return \Voyager\Contracts\Process\ProcessResult
     *
     * @throws \InvalidArgumentException
     */
    public function run(?callable $output = null)
    {
        call_user_func($this->callback, $this);

        return (new Collection($this->pendingProcesses))
            ->reduce(function ($previousProcessResult, $pendingProcess, $key) use ($output) {
                if (! $pendingProcess instanceof PendingProcess) {
                    throw new InvalidArgumentException('Process pipe must only contain pending processes.');
                }

                if ($previousProcessResult && $previousProcessResult->failed()) {
                    return $previousProcessResult;
                }

                return $pendingProcess->when(
                    $previousProcessResult,
                    fn () => $pendingProcess->input($previousProcessResult->output())
                )->run(output: $output ? function ($type, $buffer) use ($key, $output) {
                    $output($type, $buffer, $key);
                } : null);
            });
    }

    /**
     * Runs the processes in the pipe without blocking the loop: each one starts once the one
     * before it has finished, with its output as input, and a failure ends the pipe there.
     *
     * @param  callable|null  $output  handed each process's output as ($type, $buffer, $key)
     * @return \Voyager\Contracts\IOPools\Promise  the last process's ProcessResult
     *
     * @throws \InvalidArgumentException
     */
    public function runAsync(?callable $output = null): Promise
    {
        call_user_func($this->callback, $this);

        foreach ($this->pendingProcesses as $pendingProcess) {
            if (! $pendingProcess instanceof PendingProcess) {
                throw new InvalidArgumentException('Process pipe must only contain pending processes.');
            }
        }

        $done = ControlPanel::getInstance()->get(Loop::class)->promise();
        $this->runStage($done, array_keys($this->pendingProcesses), 0, null, $output);

        return $done;
    }

    /**
     * @param  list<array-key>  $keys
     */
    protected function runStage(Promise $done, array $keys, int $stage, mixed $previous, ?callable $output): void
    {
        if ($stage === count($keys) || ($previous && $previous->failed())) {
            $done->resolve($previous);

            return;
        }

        $key = $keys[$stage];
        $pendingProcess = $this->pendingProcesses[$key];

        if ($previous) {
            $pendingProcess->input($previous->output());
        }

        $pendingProcess->runAsync(output: $output ? function ($type, $buffer) use ($key, $output) {
            $output($type, $buffer, $key);
        } : null)->then(
            function (mixed $result) use ($done, $keys, $stage, $output): mixed {
                $this->runStage($done, $keys, $stage + 1, $result, $output);

                return $result;
            },
            function (Throwable $e) use ($done): null {
                $done->reject($e);

                return null;
            },
        );
    }

    /**
     * Dynamically proxy methods calls to a new pending process.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return \Voyager\Process\PendingProcess
     */
    public function __call($method, $parameters)
    {
        return tap($this->factory->{$method}(...$parameters), function ($pendingProcess) {
            $this->pendingProcesses[] = $pendingProcess;
        });
    }
}
