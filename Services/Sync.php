<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

/**
 * The scheduled job that fills the mirror, one capped run at a time.
 *
 * A run does not try to finish. It walks an ordered PLAN of steps, spends calls
 * until the budget guard says stop, writes down where it got to, and returns.
 * The next run picks the plan up at that index. This is the only design that
 * survives the two facts Almanac lives with: a full backfill is more calls than
 * anyone wants to spend at once, and a run holds a worker for as long as it
 * lasts.
 *
 * 🚨 **The plan is ordered so a HALF-DONE backfill is still a working site.**
 * Teams, then this season's rosters, then this year's recruiting class, then
 * this season's stats — roughly twenty calls, after which every page renders
 * with current data. Older seasons fill in behind that. Ordering the plan by
 * dataset instead would leave the site with every team's 2021 rushing totals
 * and no current roster, which is the same number of calls spent and nothing to
 * show for them.
 *
 * 🚨 **The plan walks seasons NEWEST FIRST**, and `Store` relies on it. Who a
 * player is now — his team, his jersey, his listed weight — is written from the
 * first roster the plan reaches and never overwritten by an older one. Reverse
 * the order and every senior ends up filed under the school he was a freshman
 * at.
 *
 * 🚨 **A signature guards the cursor.** It hashes the settings the plan is
 * built from, so widening `almanac_seasons_back` restarts the walk rather than
 * resuming at an index that now points at a different step. Without it,
 * changing a setting silently skips whatever the new plan inserted before the
 * saved position.
 */
final class Sync
{
    /** Steps that cost no call and run at the end of every tick. */
    private const FINALIZE = ['link_recruits', 'career_spans', 'forums'];

    public function __construct(
        private readonly Cfbd $cfbd,
        private readonly Store $store,
        private readonly Settings $settings,
        private readonly Budget $budget,
    ) {
    }

    /**
     * One tick.
     *
     * @return array<string, mixed> what happened, for the admin screen
     */
    public function run(?int $now = null): array
    {
        $now = $now ?? time();

        if (!$this->settings->enabled()) {
            return $this->finish('disabled', '', $now);
        }

        if (!$this->cfbd->configured()) {
            return $this->finish('not_configured', '', $now);
        }

        $cursor = $this->settings->cursor();
        $mode = (string) ($cursor['mode'] ?? 'backfill');

        /*
         * 🚨 A finished mirror sits still until it is due, and this is what
         * makes a DAILY schedule affordable. The tick has to be daily so a
         * backfill makes steady progress without an operator waiting a week
         * between chunks — but once it is complete, walking the refresh plan
         * every day would spend around seven hundred calls a month rewriting
         * rows that only change on Saturday. Almost every tick returns here,
         * having touched nothing.
         */
        if ($mode === 'refresh' && (int) ($cursor['i'] ?? 0) === 0 && !$this->due($now)) {
            return $this->finish('idle', '', $now, ['mode' => $mode]);
        }

        $plan = $this->plan($mode, $now);
        $signature = $this->signature($mode, $now);

        /* A plan that no longer matches the cursor starts again. */
        $index = ($cursor['sig'] ?? null) === $signature ? (int) ($cursor['i'] ?? 0) : 0;

        $error = '';
        $stepsRun = 0;

        while ($index < count($plan)) {
            if (!$this->budget->may()) {
                $error = $this->budget->reason();
                break;
            }

            $failure = $this->execute($plan[$index]);

            /*
             * 🚨 The cursor advances past a step that returned rows OR that
             * returned nothing at all, and stops only on a real fault. CFBD's
             * early coverage is patchy in a way that looks like an outage —
             * 2004 answers with season stats and 2005 answers with none — so a
             * sync that refused to move past an empty year would stall there
             * for good.
             */
            if ($failure !== '' && $failure !== 'empty') {
                $error = $failure;
                break;
            }

            $index++;
            $stepsRun++;
        }

        /* Costs nothing and keeps a partly-filled mirror coherent. */
        foreach (self::FINALIZE as $step) {
            $this->execute(['kind' => $step]);
        }

        $complete = $index >= count($plan);

        if ($complete) {
            /*
             * The backfill is done; from here on only the current season needs
             * refreshing. The index resets so the shorter plan is walked from
             * its start next tick.
             */
            $mode = 'refresh';
            $index = 0;
            $this->settings->put('almanac_sync_ok_at', (string) $now);
        }

        $this->settings->saveCursor([
            'sig' => $complete ? '' : $signature,
            'mode' => $mode,
            'i' => $index,
        ]);

        $status = $error !== '' ? $error : ($complete ? 'complete' : 'working');

        return $this->finish($status, $error, $now, [
            'steps' => $stepsRun,
            'remaining' => max(0, count($plan) - $index),
            'calls' => $this->budget->spent(),
            'budget' => $this->budget->remaining(),
            'mode' => $mode,
        ]);
    }

    /**
     * Has enough time passed to walk the refresh plan again?
     *
     * 🚨 Only consulted when a refresh is at its START. A refresh that ran out
     * of budget part-way carries on next tick whatever the interval says —
     * otherwise a plan too big for one run would stall half-done for a week,
     * every week, and never complete.
     */
    public function due(?int $now = null): bool
    {
        $last = $this->settings->lastComplete();

        return $last === null
            || ($now ?? time()) - $last >= $this->settings->refreshDays() * 86400;
    }

    /**
     * The ordered work.
     *
     * @return list<array<string, mixed>>
     */
    public function plan(string $mode, ?int $now = null): array
    {
        $now = $now ?? time();
        $season = $this->settings->season($now);
        $steps = [];

        /* Everything resolves a team NAME to an id, so this is always first. */
        $steps[] = ['kind' => 'teams', 'year' => $season];

        /* The current season, in the order that makes the site usable soonest. */
        $steps[] = ['kind' => 'roster', 'year' => $season, 'current' => true];
        $steps[] = ['kind' => 'recruits', 'year' => $season + 1];
        $steps[] = ['kind' => 'team_recruiting', 'year' => $season + 1];
        $steps[] = ['kind' => 'records', 'year' => $season];
        $steps[] = ['kind' => 'talent', 'year' => $season];
        $steps[] = ['kind' => 'ratings', 'year' => $season];

        foreach (Cfbd::STAT_CATEGORIES as $category) {
            $steps[] = ['kind' => 'stats', 'year' => $season, 'category' => $category];
        }

        /*
         * 🚨 BOTH cycles, and the current one first.
         *
         * CFBD stamps a portal entry with the season the player is transferring
         * INTO, so during the 2026 season the entries that exist are 2026's.
         * Asking only for `$season + 1` fetched a cycle that has not opened yet
         * and stored nothing, which rendered as a portal page headed "2027" and
         * showing nothing at all — a page that looks broken while the sync
         * reports success.
         */
        $steps[] = ['kind' => 'portal', 'year' => $season];
        $steps[] = ['kind' => 'portal', 'year' => $season + 1];

        if ($this->settings->gameLogs()) {
            foreach ($this->weeks($mode, $now) as $week) {
                $steps[] = ['kind' => 'game_stats', 'year' => $season, 'week' => $week];
            }
        }

        if ($mode === 'refresh') {
            return $steps;
        }

        /*
         * History. Each older season is completed before the next is started,
         * so a backfill that stops early leaves whole seasons rather than a
         * thin layer across all of them.
         */
        for ($offset = 1; $offset < $this->settings->seasonsBack(); $offset++) {
            $year = $season - $offset;

            $steps[] = ['kind' => 'roster', 'year' => $year, 'current' => false];

            foreach (Cfbd::STAT_CATEGORIES as $category) {
                $steps[] = ['kind' => 'stats', 'year' => $year, 'category' => $category];
            }

            $steps[] = ['kind' => 'records', 'year' => $year];
            $steps[] = ['kind' => 'talent', 'year' => $year];
            $steps[] = ['kind' => 'ratings', 'year' => $year];
        }

        /*
         * Older recruiting classes. Last, because their whole job is to give a
         * player already on a roster a high school — nobody browses the 2019
         * board — and the newest class, which people do browse, is already in
         * above.
         */
        for ($offset = 0; $offset < $this->settings->recruitClasses(); $offset++) {
            $year = $season - $offset;

            $steps[] = ['kind' => 'recruits', 'year' => $year];
            $steps[] = ['kind' => 'team_recruiting', 'year' => $year];
        }

        return $steps;
    }

    /**
     * Which weeks of game logs to ask for.
     *
     * 🚨 A backfill takes the lot; a refresh takes only the last two. Game logs
     * are the most expensive dataset — a week is a call and there is no
     * whole-season form of the endpoint — and re-fetching all sixteen every
     * week would spend more than everything else in the plan combined to
     * rewrite rows that have not changed since September. Two, rather than one,
     * because a stat correction lands after the fact.
     *
     * @return list<int>
     */
    private function weeks(string $mode, int $now): array
    {
        if ($mode !== 'refresh') {
            return range(1, 16);
        }

        $week = $this->currentWeek($now);

        return $week <= 1 ? [1] : [$week - 1, $week];
    }

    /**
     * Roughly which week the season is in.
     *
     * Approximate on purpose: Almanac holds no schedule of its own, and the
     * cost of being a week out is one extra call at a boundary.
     */
    private function currentWeek(int $now): int
    {
        $start = strtotime('last Saturday of August ' . date('Y', $now));

        if ($start === false || $now < $start) {
            return 1;
        }

        return max(1, min(16, (int) floor(($now - $start) / (7 * 86400)) + 1));
    }

    /**
     * @param array<string, mixed> $step
     * @return string '' worked, 'empty' nothing there, anything else a fault
     */
    private function execute(array $step): string
    {
        $kind = (string) $step['kind'];
        $year = (int) ($step['year'] ?? 0);

        switch ($kind) {
            case 'teams':
                [$rows, $error] = $this->cfbd->teams($year);

                if ($error === '') {
                    $this->store->upsertTeams($rows);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            case 'roster':
                [$rows, $error] = $this->cfbd->roster($year);

                if ($error === '') {
                    ($step['current'] ?? false)
                        ? $this->store->upsertRoster($rows, $year)
                        : $this->store->upsertRosterHistoryOnly($rows, $year);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            case 'stats':
                [$rows, $error] = $this->cfbd->seasonStats($year, (string) $step['category']);

                if ($error === '') {
                    $this->store->upsertSeasonStats($rows, $year);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            case 'game_stats':
                [$rows, $error] = $this->cfbd->gameStats($year, (int) $step['week']);

                if ($error === '') {
                    $this->store->upsertGameStats($rows);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            case 'recruits':
                [$rows, $error] = $this->cfbd->recruits($year);

                if ($error === '') {
                    $this->store->upsertRecruits($rows);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            case 'team_recruiting':
                [$rows, $error] = $this->cfbd->teamRecruiting($year);

                if ($error === '') {
                    $this->store->upsertTeamRecruiting($rows);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            case 'portal':
                [$rows, $error] = $this->cfbd->portal($year);

                if ($error === '') {
                    $this->store->upsertTransfers($rows);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            case 'records':
                [$rows, $error] = $this->cfbd->records($year);

                if ($error === '') {
                    $this->store->upsertTeamSeasons($rows, [
                        'wins', 'losses', 'ties', 'conf_wins', 'conf_losses',
                    ]);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            case 'talent':
                [$rows, $error] = $this->cfbd->talent($year);

                if ($error === '') {
                    $this->store->upsertTeamSeasons($rows, ['talent']);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            case 'ratings':
                [$rows, $error] = $this->cfbd->ratings($year);

                if ($error === '') {
                    $this->store->upsertTeamSeasons($rows, ['sp_rating', 'sp_rank']);
                }

                return $error ?: ($rows === [] ? 'empty' : '');

            /* The three below cost nothing and touch no provider. */
            case 'link_recruits':
                $this->store->linkRecruits();

                return '';

            case 'career_spans':
                $this->store->recomputeCareerSpans();

                return '';

            case 'forums':
                $this->store->resolveForums();

                return '';
        }

        return '';
    }

    /**
     * What the plan depends on. Change any of it and the walk restarts.
     */
    private function signature(string $mode, int $now): string
    {
        return substr(hash('sha256', implode('|', [
            $mode,
            (string) $this->settings->season($now),
            (string) $this->settings->seasonsBack(),
            (string) $this->settings->recruitClasses(),
            $this->settings->gameLogs() ? '1' : '0',
        ])), 0, 16);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function finish(string $status, string $error, int $now, array $extra = []): array
    {
        $this->settings->put('almanac_sync_status', $status);
        $this->settings->put('almanac_sync_error', $error);
        $this->settings->put('almanac_sync_at', (string) $now);

        return ['status' => $status, 'error' => $error] + $extra;
    }
}
