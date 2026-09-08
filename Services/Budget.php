<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

/**
 * What is left of the month's calls, and whether Roster may spend one.
 *
 * CollegeFootballData's free tier allows **a thousand calls per calendar
 * month**, and it reports what is left in `x-calllimit-remaining` on every
 * response. Roster's first backfill is roughly a hundred calls, and a
 * re-backfill after somebody widens `almanac_seasons_back` can be several
 * times that — enough to end a month early if nothing is watching.
 *
 * Picks draws on the same allowance, but in bursts rather than continuously: a
 * season's fixtures before week one, and again for the bowls. So the reserve is
 * not a standing allocation held back for it all year, just enough headroom
 * that a fixture sync landing in the same month as a backfill does not have to
 * wait behind it.
 *
 * Two independent limits, because they fail differently:
 *
 *  - **The reserve** is a floor on the provider's own counter, so a runaway
 *    backfill stops while calls remain rather than at zero.
 *  - **The run cap** is a ceiling on a single tick, so one scheduled run cannot
 *    spend the month even if the counter says it could. It also bounds how long
 *    a run holds a worker, which is the limit that matters on a Saturday.
 *
 * 🚨 **An unknown budget is spendable.** Before the first call of a run nothing
 * has reported a figure, and a guard that refused to start without one would
 * mean Roster never made its first call and so never learned the number —
 * a deadlock that looks exactly like a broken API key. The first call goes out;
 * every call after it is checked.
 */
final class Budget
{
    private ?int $remaining = null;

    private int $spent = 0;

    public function __construct(private readonly Settings $settings)
    {
        $stored = $this->settings->get('almanac_budget_remaining');

        if ($stored !== '' && ctype_digit($stored)) {
            $this->remaining = (int) $stored;
        }
    }

    /**
     * Learn the figure from a response.
     *
     * 🚨 Recorded even when the call FAILED. A 401 still carries the header and
     * still cost an call; treating only successes as spending is how a run with
     * a bad key burns the month believing it has spent nothing.
     *
     * @param array<string, string> $headers lowercased
     */
    public function record(array $headers): void
    {
        $this->spent++;

        $value = $headers['x-calllimit-remaining'] ?? '';

        if ($value === '' || !ctype_digit(trim($value))) {
            /*
             * No header. Assume the call counted anyway — the alternative is
             * to believe a provider that has stopped reporting, which is the
             * moment a guard is most needed.
             */
            if ($this->remaining !== null) {
                $this->remaining--;
            }

            return;
        }

        $this->remaining = (int) trim($value);

        $this->settings->put('almanac_budget_remaining', (string) $this->remaining);
        $this->settings->put('almanac_budget_at', (string) time());
    }

    /**
     * May another call go out?
     *
     * @param int $cost how many calls the caller is about to make
     */
    public function may(int $cost = 1): bool
    {
        if ($this->spent + $cost > $this->settings->runCap()) {
            return false;
        }

        if ($this->remaining === null) {
            return true;   // see the note on the class — first call of a run
        }

        return ($this->remaining - $cost) >= $this->settings->budgetReserve();
    }

    /** Why a run stopped, in words an operator can act on. */
    public function reason(): string
    {
        if ($this->remaining !== null
            && ($this->remaining - 1) < $this->settings->budgetReserve()) {
            return 'budget_reserve';
        }

        return 'run_cap';
    }

    public function remaining(): ?int
    {
        return $this->remaining;
    }

    public function spent(): int
    {
        return $this->spent;
    }
}
