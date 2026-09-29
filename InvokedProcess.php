<?php

namespace Voyager\Process;

use Closure;
use LogicException;
use Voyager\Vessel\ControlPanel;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\Process\InvokedProcess as InvokedProcessContract;
use Voyager\Process\Exceptions\ProcessTimedOutException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process;

class InvokedProcess implements InvokedProcessContract
{
    /**
     * The underlying process instance.
     *
     * @var \Symfony\Component\Process\Process
     */
    protected $process;

    /** The callback start() was given, handed the output as it is read. */
    protected ?Closure $started_output = null;

    /** The callback waitAsync() was given, handed the output read from then on. */
    protected ?Closure $waiting_output = null;

    /** The watch waitAsync() put on the loop. */
    protected ?ProcessWatch $watch = null;

    /**
     * Create a new invoked process instance.
     *
     * @param  \Symfony\Component\Process\Process  $process
     */
    public function __construct(Process $process)
    {
        $this->process = $process;
    }

    /**
     * Get the process ID if the process is still running.
     *
     * @return int|null
     */
    public function id()
    {
        return $this->process->getPid();
    }

    /**
     * Get the command line for the process.
     *
     * @return string
     */
    public function command()
    {
        return $this->process->getCommandLine();
    }

    /**
     * Send a signal to the process.
     *
     * @param  int  $signal
     * @return $this
     */
    public function signal(int $signal)
    {
        $this->process->signal($signal);

        return $this;
    }

    /**
     * Stop the process if it is still running.
     *
     * @param  float  $timeout
     * @param  int|null  $signal
     * @return int|null
     */
    public function stop(float $timeout = 10, ?int $signal = null)
    {
        return $this->process->stop($timeout, $signal);
    }

    /**
     * Determine if the process is still running.
     *
     * @return bool
     */
    public function running()
    {
        return $this->process->isRunning();
    }

    /**
     * Get the standard output for the process.
     *
     * @return string
     */
    public function output()
    {
        return $this->process->getOutput();
    }

    /**
     * Get the error output for the process.
     *
     * @return string
     */
    public function errorOutput()
    {
        return $this->process->getErrorOutput();
    }

    /**
     * Get the latest standard output for the process.
     *
     * @return string
     */
    public function latestOutput()
    {
        return $this->process->getIncrementalOutput();
    }

    /**
     * Get the latest error output for the process.
     *
     * @return string
     */
    public function latestErrorOutput()
    {
        return $this->process->getIncrementalErrorOutput();
    }

    /**
     * Ensure that the process has not timed out.
     *
     * @return void
     *
     * @throws \Voyager\Process\Exceptions\ProcessTimedOutException
     */
    public function ensureNotTimedOut()
    {
        try {
            $this->process->checkTimeout();
        } catch (SymfonyTimeoutException $e) {
            throw new ProcessTimedOutException($e, new ProcessResult($this->process));
        }
    }

    /**
     * Wait for the process to finish.
     *
     * @param  callable|null  $output
     * @return \Voyager\Process\ProcessResult
     *
     * @throws \Voyager\Process\Exceptions\ProcessTimedOutException
     */
    public function wait(?callable $output = null)
    {
        try {
            $this->process->wait($output);

            return new ProcessResult($this->process);
        } catch (SymfonyTimeoutException $e) {
            throw new ProcessTimedOutException($e, new ProcessResult($this->process));
        }
    }

    /**
     * Wait for the process to finish without blocking the loop.
     *
     * @param  callable|null  $output  handed the output read from now on, as ($type, $buffer)
     * @return \Voyager\Contracts\IOPools\Promise  the ProcessResult, or a ProcessTimedOutException
     */
    public function waitAsync(?callable $output = null): Promise
    {
        if (! is_null($output)) {
            if ($this->process->isOutputDisabled()) {
                $refused = ControlPanel::getInstance()->get(Loop::class)->promise();
                $refused->reject(new LogicException('Output has been disabled, enable it to allow the use of a callback.'));

                return $refused;
            }

            $this->waiting_output = $output(...);
        }

        $this->watch ??= ProcessWatch::watch(
            $this->process, ! is_null($this->started_output) || ! is_null($this->waiting_output),
        );

        return $this->watch->promise();
    }

    /**
     * The callback PendingProcess::start() hands Symfony when the process starts: the output goes
     * to start()'s callback, and to waitAsync()'s once one is given.
     *
     * @internal
     */
    public function relay(?callable $output): Closure
    {
        $this->started_output = is_null($output) ? null : $output(...);

        return function (string $type, string $buffer): void {
            if ($this->started_output) {
                ($this->started_output)($type, $buffer);
            }

            if ($this->waiting_output) {
                ($this->waiting_output)($type, $buffer);
            }
        };
    }

    /**
     * Wait until the given callback returns true.
     *
     * @param  callable|null  $output
     * @return \Voyager\Process\ProcessResult
     *
     * @throws \Voyager\Process\Exceptions\ProcessTimedOutException
     */
    public function waitUntil(?callable $output = null)
    {
        try {
            $this->process->waitUntil($output);

            return new ProcessResult($this->process);
        } catch (SymfonyTimeoutException $e) {
            throw new ProcessTimedOutException($e, new ProcessResult($this->process));
        }
    }
}
