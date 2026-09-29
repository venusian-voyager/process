<?php

namespace Voyager\Process;

use Closure;
use Throwable;
use Voyager\Vessel\ControlPanel;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\ProcessExit;
use Voyager\IOPools\Waiter\Wakes\ControlSignal;
use Voyager\Contracts\IOPools\LoopResources\Deadlined;
use Voyager\Process\Exceptions\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;

/**
 * Waits on a running process without blocking the loop, and settles a promise with its result
 * once it exits. The watch is on the loop only until then.
 *
 * The exit wakes the loop. On kqueue that is the process filter, a ProcessExit wake. Elsewhere it
 * is SIGCHLD through the Waiter's signal relay, which the kernel sends the parent whenever a child
 * exits: the relay handles the signal, never ignores it, since an ignored SIGCHLD has the kernel
 * reap children itself and their exit codes are lost. For the same reason kqueue gets the process
 * filter rather than SIGCHLD: its backend ignores the signals it watches. The first check runs on
 * the first turn after the watch registers, once the wake is set up, so an exit that came before
 * it is found there.
 *
 * A timeout is checked once, when it runs out: the process's start plus its timeout is a deadline
 * of the watch's own. Symfony Process keeps its pipes to itself, so output never wakes the loop:
 * the watch reads it every POLL_NS while an output callback wants it as it arrives, while an idle
 * timeout (which counts from the last output) needs checking, and when no exit wake exists (no
 * pcntl on a backend without a process filter). Otherwise it checks on no timer: the exit wake
 * settles it, and the whole output is in the result.
 */
final class ProcessWatch extends WakeSource implements Deadlined
{
    /** The loop's default pace. */
    public const int POLL_NS = 16_000_000;

    private readonly string $name;

    private readonly bool $polls;

    /**
     * Taken once, when the watch starts: asking Symfony later checks on the process, which reaps
     * it once it has exited, and the wake for its exit would be withdrawn before it was heard.
     * Null when the process was already gone, which the first check settles.
     */
    private readonly ?int $pid;

    private readonly Promise $promise;

    private ?int $next_check;

    /** When the process's timeout runs out, as hrtime(true); null without one. */
    private readonly ?int $timeout_at;

    private bool $done = false;

    /**
     * @param bool $streams an output callback is waiting for the process's output as it arrives
     */
    private function __construct(
        private readonly Process $process,
        private readonly Loop $loop,
        bool $streams,
    ) {
        $this->name = 'process.watch.'.spl_object_id($this);
        $this->promise = $loop->promise();
        $this->pid = $process->getPid();
        $this->next_check = hrtime(true);

        // A millisecond past the limit: Symfony counts a process timed out only once it is over.
        $this->timeout_at = is_null($timeout = $process->getTimeout())
            ? null
            : hrtime(true) + (int) (($process->getStartTime() + $timeout - microtime(true)) * 1e9) + 1_000_000;

        $this->polls = $streams
            || ! is_null($process->getIdleTimeout())
            || $this->exitWakes() === [];
    }

    /**
     * Starts watching a process the loop's container knows the loop for.
     *
     * @param bool $streams an output callback is waiting for the process's output as it arrives
     */
    public static function watch(Process $process, bool $streams): self
    {
        $watch = new self($process, ControlPanel::getInstance()->get(Loop::class), $streams);
        $watch->loop->resource($watch->name, $watch);

        return $watch;
    }

    /** @return Promise the ProcessResult, or a ProcessTimedOutException */
    public function promise(): Promise
    {
        return $this->promise;
    }

    public function wakes(): array
    {
        return $this->done ? [] : $this->exitWakes();
    }

    public function woke(array $fired): void
    {
        $this->check();
    }

    public function dueAt(): ?int
    {
        if ($this->done) {
            return null;
        }

        return match (true) {
            is_null($this->next_check) => $this->timeout_at,
            is_null($this->timeout_at) => $this->next_check,
            default => min($this->next_check, $this->timeout_at),
        };
    }

    public function fire(): void
    {
        $this->next_check = null;
        $this->check();

        if (! $this->done && $this->polls) {
            $this->next_check = hrtime(true) + self::POLL_NS;
        }
    }

    /**
     * @return list<ProcessExit|ControlSignal>
     */
    private function exitWakes(): array
    {
        return match (true) {
            is_null($this->pid) => [],
            $this->loop->supports(WakeReason::PROCESS_EXIT) => [new ProcessExit($this->pid)],
            defined('SIGCHLD') && $this->loop->supports(WakeReason::CONTROL_SIGNAL) => [new ControlSignal(SIGCHLD)],
            default => [],
        };
    }

    private function check(): void
    {
        if ($this->done) {
            return;
        }

        try {
            // Reads whatever output has arrived, handing it to the output callbacks.
            if ($this->process->isRunning()) {
                $this->process->checkTimeout();

                return;
            }

            // Gone: the last of its output, and its exit code. wait() returns at once.
            $this->process->wait();
            $this->settle(fn () => $this->promise->resolve(new ProcessResult($this->process)));
        } catch (SymfonyTimeoutException $e) {
            $this->settle(fn () => $this->promise->reject(new ProcessTimedOutException($e, new ProcessResult($this->process))));
        } catch (Throwable $e) {
            $this->settle(fn () => $this->promise->reject($e));
        }
    }

    private function settle(Closure $outcome): void
    {
        $this->done = true;
        $this->loop->forget($this->name);
        $outcome();
    }
}
