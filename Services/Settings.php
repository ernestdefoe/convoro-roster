<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

use Convoro\Engine\Database\Connection;

/**
 * How this site runs Almanac, and what the last sync found.
 *
 * Follows Picks' service of the same name: everything is a string because that
 * is what the settings table holds, `save()` writes only keys listed in
 * DEFAULTS so a stray form field cannot become a site-wide setting, and a blank
 * credential LEAVES THE STORED ONE ALONE rather than clearing it.
 *
 * 🚨 **The API key is borrowed from Picks when Almanac has none of its own.**
 * Both extensions talk to CollegeFootballData, both spend from the same
 * thousand-calls-a-month allowance, and asking an operator to paste the same
 * key twice is how a site ends up with two keys, two budgets and no idea which
 * one ran out. Almanac's own key wins if set, so a site that wants them
 * separate can have that.
 */
/**
 * 🚨 Not `final`, and for one reason: the test suite runs against the SITE'S
 * OWN database, so a test that exercised the budget guard through the real
 * class would write `almanac_budget_remaining` into live settings. A double
 * stands in front of it instead. That is the whole reason; there is no other
 * subclass and there should not be one.
 */
class Settings
{
    /** The stored defaults, and the list of keys `save()` will accept. */
    private const DEFAULTS = [
        'almanac_enabled' => '0',

        /*
         * 🚨 A credential. Write-only; blank means keep. Usually left empty on
         * a site running Picks — see cfbdKey().
         */
        'almanac_cfbd_key' => '',

        /* The season the front pages present. Blank means "work it out". */
        'almanac_season' => '',

        /*
         * How many seasons back to carry. Six covers a fifth-year senior's
         * whole career, which is the promise the player page makes; there is
         * no reason to hold 2011 to render a 2026 roster.
         */
        'almanac_seasons_back' => '6',

        /*
         * How many recruiting classes to carry. Eight covers everybody on a
         * current roster including redshirts, which is what makes a true
         * freshman's page worth opening.
         */
        'almanac_recruit_classes' => '8',

        /* Per-game lines. The most expensive dataset; optional. */
        'almanac_game_logs' => '1',

        /*
         * Calls the sync will NOT spend, so Almanac can never take the whole
         * month on its own.
         *
         * Deliberately small. Picks draws on the same thousand-a-month
         * allowance but spends it in BURSTS — a season's fixtures fetched once
         * before week one, and again for the bowls — rather than continuously,
         * so it does not need a standing reserve held back for it all year. 50
         * leaves room for a fixture sync to land in the same month as a
         * backfill without either having to wait.
         */
        'almanac_budget_reserve' => '50',

        /* Calls a single run may spend, so one tick cannot eat the month. */
        'almanac_run_cap' => '40',

        /*
         * 🚨 Days between refreshes once the backfill is done. The schedule
         * ticks DAILY but a finished mirror returns immediately unless this
         * much time has passed — college football is a weekly sport, and
         * re-fetching every roster and every stat category every day would
         * spend about seven hundred calls a month rewriting rows that changed
         * on Saturday. While the backfill is still running, every tick works.
         */
        'almanac_refresh_days' => '7',

        /* What the last run wrote down, for the admin screen and the pages. */
        'almanac_sync_status' => '',
        'almanac_sync_error' => '',
        'almanac_sync_at' => '',
        'almanac_sync_ok_at' => '',
        'almanac_sync_cursor' => '',
        'almanac_budget_remaining' => '',
        'almanac_budget_at' => '',
    ];

    /** Never rendered back into a form; blank on save means "keep". */
    private const CREDENTIALS = ['almanac_cfbd_key'];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Connection $db)
    {
    }

    public function get(string $key): string
    {
        $this->load();

        return $this->values[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    public function enabled(): bool
    {
        return $this->get('almanac_enabled') === '1';
    }

    public function gameLogs(): bool
    {
        return $this->get('almanac_game_logs') === '1';
    }

    /**
     * The CollegeFootballData key, Almanac's own if set and Picks' otherwise.
     *
     * 🚨 Read straight from the settings row rather than through Picks' class,
     * because Almanac does not depend on Picks and must not fatal on a site
     * that has never installed it. A missing row is simply an empty string.
     */
    public function cfbdKey(): string
    {
        $own = trim($this->get('almanac_cfbd_key'));

        if ($own !== '') {
            return $own;
        }

        $this->load();

        return trim($this->values['picks_cfbd_key'] ?? '');
    }

    public function configured(): bool
    {
        return $this->cfbdKey() !== '';
    }

    /**
     * The season Almanac presents.
     *
     * 🚨 Falls forward, not back. College football's season is named for the
     * calendar year it starts in, so January and February belong to the year
     * before — without this the whole site reads as empty every bowl season,
     * which is exactly when people are looking.
     */
    public function season(?int $now = null): int
    {
        $set = (int) $this->get('almanac_season');

        if ($set > 1900) {
            return $set;
        }

        $now = $now ?? time();
        $year = (int) date('Y', $now);
        $month = (int) date('n', $now);

        return $month <= 2 ? $year - 1 : $year;
    }

    public function seasonsBack(): int
    {
        return max(1, min(20, (int) $this->get('almanac_seasons_back') ?: 6));
    }

    public function recruitClasses(): int
    {
        return max(1, min(25, (int) $this->get('almanac_recruit_classes') ?: 8));
    }

    public function budgetReserve(): int
    {
        return max(0, (int) $this->get('almanac_budget_reserve'));
    }

    public function runCap(): int
    {
        return max(1, min(500, (int) $this->get('almanac_run_cap') ?: 40));
    }

    public function refreshDays(): int
    {
        return max(1, min(60, (int) $this->get('almanac_refresh_days') ?: 7));
    }

    /**
     * When the mirror was last brought fully up to date, or null if it never
     * has been.
     */
    public function lastComplete(): ?int
    {
        $at = $this->get('almanac_sync_ok_at');

        return $at !== '' && ctype_digit($at) ? (int) $at : null;
    }

    /**
     * Things an operator needs to know about, counted for the admin menu pip.
     *
     * 🚨 Deliberately narrow: switched on and unable to answer. That state is
     * otherwise invisible — an Almanac with a rejected API key looks exactly
     * like one in the off season — whereas "switched off" is a choice somebody
     * made and does not deserve a badge nagging them about it.
     *
     * This closure runs on every admin page, so it must stay a settings read.
     */
    public function problems(): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        if (!$this->configured()) {
            return 1;
        }

        return in_array(
            $this->get('almanac_sync_status'),
            ['invalid_key', 'unreachable', 'rate_limited', 'budget_reserve'],
            true,
        ) ? 1 : 0;
    }

    /** @return array<string, mixed> */
    public function cursor(): array
    {
        $decoded = json_decode($this->get('almanac_sync_cursor'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $cursor */
    public function saveCursor(array $cursor): void
    {
        $this->put('almanac_sync_cursor', json_encode($cursor) ?: '');
        $this->values = null;
    }

    /** @param array<string, string|int> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            /*
             * 🚨 A blank credential leaves the stored one alone. The form
             * cannot render a key back — a credential printed into a page is a
             * credential in a browser cache and in whatever screenshot gets
             * attached to a support ticket — so a blank box is ambiguous, and
             * it has to mean "I did not touch this". Somebody who came to
             * change the season must not stop every sync on the way out.
             */
            if (in_array($key, self::CREDENTIALS, true) && trim((string) $value) === '') {
                continue;
            }

            $this->put($key, (string) $value);
        }

        $this->values = null;
    }

    public function put(string $key, string $value): void
    {
        $existing = $this->db->table('settings')->where('key', $key)->first();

        $existing === null
            ? $this->db->table('settings')->insertGetId(['key' => $key, 'value' => $value])
            : $this->db->table('settings')->where('key', $key)->updateAll(['value' => $value]);

        $this->values = null;
    }

    private function load(): void
    {
        if ($this->values !== null) {
            return;
        }

        $this->values = [];

        /*
         * Almanac's own keys plus Picks' credential — see cfbdKey(). Two narrow
         * reads rather than loading the whole settings table on every page.
         */
        foreach ($this->db->table('settings')->whereLike('key', 'almanac\_%')->get() as $row) {
            $this->values[(string) $row['key']] = (string) ($row['value'] ?? '');
        }

        $borrowed = $this->db->table('settings')->where('key', 'picks_cfbd_key')->first();

        if ($borrowed !== null) {
            $this->values['picks_cfbd_key'] = (string) ($borrowed['value'] ?? '');
        }
    }
}
