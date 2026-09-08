<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

/**
 * CollegeFootballData: who the teams are, who is on them, what they did, and
 * who is on the way.
 *
 * 🚨 Every method returns `[$rows, $error]` and NEVER throws. `$error` empty
 * means the call worked; `$rows` empty with no error means the provider
 * genuinely has nothing for that question. Those are different facts and the
 * sync has to tell them apart, because one means "store this" and the other
 * means "leave everything alone and try again later".
 *
 * 🚨 **An empty year is normal, not a failure.** CFBD's early coverage is
 * patchy in a way that looks like an outage: 2004 returns season stats and
 * 2005 returns none at all for the same team and category. A sync that treated
 * an empty year as an error would stall its cursor forever on a year that is
 * simply blank.
 *
 * 🚨 The API key goes in a header and is never in a URL. A query string is in
 * the access log of every proxy between here and them.
 *
 * 🚨 Every call is counted against the shared monthly allowance by `Budget`,
 * whether it succeeded or not.
 */
final class Cfbd
{
    private const BASE = 'https://api.collegefootballdata.com';

    /** Only the top division. Roster is an FBS almanac. */
    public const CLASSIFICATION = 'fbs';

    /**
     * The ten categories CFBD splits season stats into. Fetched one at a time
     * rather than as a whole year: a full year is a 23MB body and 139,000 rows
     * decoded into PHP memory at once, and per-category the run is resumable
     * at a granularity the cursor can actually record.
     */
    public const STAT_CATEGORIES = [
        'passing', 'rushing', 'receiving', 'defensive', 'fumbles',
        'interceptions', 'kicking', 'punting', 'kickReturns', 'puntReturns',
    ];

    public function __construct(
        private readonly Http $http,
        private readonly Settings $settings,
        private readonly Budget $budget,
    ) {
    }

    public function configured(): bool
    {
        return $this->settings->configured();
    }

    /**
     * Every FBS programme.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function teams(int $year): array
    {
        [$rows, $error] = $this->fetch('/teams', ['year' => $year]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            /*
             * 🚨 Filtered here rather than in the query. The endpoint has
             * answered with other divisions before — Picks documents the same
             * trap — and a Division II programme in `almanac_teams` is a
             * conference page with a school nobody expects and a logo that
             * 404s.
             */
            if (strtolower((string) ($row['classification'] ?? '')) !== self::CLASSIFICATION) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $school = trim((string) ($row['school'] ?? ''));

            if ($id < 1 || $school === '') {
                continue;
            }

            $logos = is_array($row['logos'] ?? null) ? $row['logos'] : [];

            $out[] = [
                'cfbd_id' => $id,
                'espn_id' => $id,   // CFBD reuses ESPN's team ids; Picks relies on this too
                'school' => $school,
                'mascot' => $this->str($row['mascot'] ?? null, 120),
                'abbreviation' => $this->str($row['abbreviation'] ?? null, 16),
                'conference' => (string) $this->str($row['conference'] ?? null, 100),
                'division' => $this->str($row['division'] ?? null, 100),
                'classification' => self::CLASSIFICATION,
                'color' => $this->str($row['color'] ?? null, 16),
                'alt_color' => $this->str($row['alternateColor'] ?? null, 16),
                'logo' => $this->str($logos[0] ?? null, 255),
                'logo_dark' => $this->str($logos[1] ?? null, 255),
                'venue' => $this->str($row['location']['name'] ?? null, 190),
                'venue_city' => $this->str($row['location']['city'] ?? null, 120),
                'venue_state' => $this->str($row['location']['state'] ?? null, 16),
            ];
        }

        return [$out, ''];
    }

    /**
     * Every roster in one season — one call for all of them.
     *
     * 🚨 Returns every division, roughly 30,000 players across 315 teams. The
     * caller narrows to the teams it holds; asking per team would be 130 calls
     * for the same rows.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function roster(int $year): array
    {
        [$rows, $error] = $this->fetch('/roster', ['year' => $year]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            /*
             * 🚨 Cast, not trusted. Ids arrive as JSON STRINGS and some are
             * NEGATIVE — CFBD synthesises an id for a player it cannot match to
             * an ESPN athlete. Both facts are load-bearing: see the migration.
             */
            $id = (int) ($row['id'] ?? 0);
            $team = trim((string) ($row['team'] ?? ''));

            if ($id === 0 || $team === '') {
                continue;
            }

            $first = (string) $this->str($row['firstName'] ?? null, 100);
            $last = (string) $this->str($row['lastName'] ?? null, 100);
            $name = trim($first . ' ' . $last);

            if ($name === '') {
                continue;
            }

            /*
             * `recruitIds` is the join that makes a freshman's page worth
             * opening — it points at his high-school recruiting record. Only
             * the first is kept; a player with several is a provider dedup
             * artefact, not several recruits.
             */
            $recruitIds = is_array($row['recruitIds'] ?? null) ? $row['recruitIds'] : [];

            $out[] = [
                'cfbd_id' => $id,
                'team' => $team,
                'season' => $year,
                'first_name' => $first !== '' ? $first : null,
                'last_name' => $last !== '' ? $last : null,
                'name' => $name,
                'position' => $this->str($row['position'] ?? null, 8),
                'jersey' => $this->int($row['jersey'] ?? null),
                'height' => $this->int($row['height'] ?? null),
                'weight' => $this->int($row['weight'] ?? null),
                'class_year' => $this->classYear($row['year'] ?? null),
                'home_city' => $this->str($row['homeCity'] ?? null, 120),
                'home_state' => $this->str($row['homeState'] ?? null, 16),
                'home_country' => $this->str($row['homeCountry'] ?? null, 60),
                'recruit_id' => isset($recruitIds[0]) ? (int) $recruitIds[0] : 0,
            ];
        }

        return [$out, ''];
    }

    /**
     * Season totals for one category across every team.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function seasonStats(int $year, string $category): array
    {
        [$rows, $error] = $this->fetch('/stats/player/season', [
            'year' => $year,
            'category' => $category,
        ]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $playerId = (int) ($row['playerId'] ?? 0);

            if ($playerId === 0) {
                continue;
            }

            $out[] = [
                'cfbd_id' => $playerId,
                'name' => (string) $this->str($row['player'] ?? null, 190),
                'team' => trim((string) ($row['team'] ?? '')),
                'season' => (int) ($row['season'] ?? $year),
                'position' => $this->str($row['position'] ?? null, 8),
                'category' => (string) $this->str($row['category'] ?? $category, 24),
                'stat_type' => (string) $this->str($row['statType'] ?? null, 24),
                'stat' => $this->str($row['stat'] ?? null, 24),
            ];
        }

        return [$out, ''];
    }

    /**
     * Per-game lines for one week.
     *
     * 🚨 A week is REQUIRED — `/games/players?year=2025` alone answers 400.
     * That is why game logs cost roughly sixteen calls a season and why they
     * are a setting rather than always-on.
     *
     * The payload nests four deep: game → teams → categories → types →
     * athletes. Flattened here so the sync stores rows.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function gameStats(int $year, int $week, string $seasonType = 'regular'): array
    {
        [$rows, $error] = $this->fetch('/games/players', [
            'year' => $year,
            'week' => $week,
            'seasonType' => $seasonType,
        ]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $game) {
            if (!is_array($game)) {
                continue;
            }

            $gameId = (int) ($game['id'] ?? 0);
            $teams = is_array($game['teams'] ?? null) ? $game['teams'] : [];

            if ($gameId === 0 || $teams === []) {
                continue;
            }

            /* Both sides are present, so each team's opponent is the other. */
            $names = [];

            foreach ($teams as $side) {
                if (is_array($side)) {
                    $names[] = trim((string) ($side['team'] ?? ''));
                }
            }

            foreach ($teams as $index => $side) {
                if (!is_array($side)) {
                    continue;
                }

                $team = trim((string) ($side['team'] ?? ''));
                $opponent = (string) ($names[$index === 0 ? 1 : 0] ?? '');

                foreach ((is_array($side['categories'] ?? null) ? $side['categories'] : []) as $category) {
                    if (!is_array($category)) {
                        continue;
                    }

                    $categoryName = (string) $this->str($category['name'] ?? null, 24);

                    foreach ((is_array($category['types'] ?? null) ? $category['types'] : []) as $type) {
                        if (!is_array($type)) {
                            continue;
                        }

                        $statType = (string) $this->str($type['name'] ?? null, 24);

                        foreach ((is_array($type['athletes'] ?? null) ? $type['athletes'] : []) as $athlete) {
                            if (!is_array($athlete)) {
                                continue;
                            }

                            $playerId = (int) ($athlete['id'] ?? 0);

                            if ($playerId === 0 || $categoryName === '' || $statType === '') {
                                continue;
                            }

                            $out[] = [
                                'cfbd_id' => $playerId,
                                'name' => (string) $this->str($athlete['name'] ?? null, 190),
                                'game_id' => $gameId,
                                'season' => $year,
                                'week' => $week,
                                'season_type' => $seasonType,
                                'team' => $team,
                                'opponent' => $opponent,
                                'home_away' => $this->str($side['homeAway'] ?? null, 8),
                                'category' => $categoryName,
                                'stat_type' => $statType,
                                'stat' => $this->str($athlete['stat'] ?? null, 24),
                            ];
                        }
                    }
                }
            }
        }

        return [$out, ''];
    }

    /**
     * A recruiting class.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function recruits(int $year): array
    {
        [$rows, $error] = $this->fetch('/recruiting/players', ['year' => $year]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['name'] ?? ''));

            if ($id === 0 || $name === '') {
                continue;
            }

            $committed = trim((string) ($row['committedTo'] ?? ''));

            $out[] = [
                'cfbd_id' => $id,
                /* Null where the provider has not matched him to an athlete —
                 * most of a class, and the reason this is nullable. */
                'athlete_id' => isset($row['athleteId']) && $row['athleteId'] !== null
                    ? (int) $row['athleteId']
                    : null,
                'year' => (int) ($row['year'] ?? $year),
                'ranking' => $this->int($row['ranking'] ?? null),
                'name' => $name,
                'position' => $this->str($row['position'] ?? null, 8),
                'height' => $this->int($row['height'] ?? null),
                'weight' => $this->int($row['weight'] ?? null),
                'stars' => $this->int($row['stars'] ?? null),
                'rating' => isset($row['rating']) ? round((float) $row['rating'], 4) : null,
                'high_school' => $this->str($row['school'] ?? null, 190),
                'city' => $this->str($row['city'] ?? null, 120),
                'state' => $this->str($row['stateProvince'] ?? null, 16),
                'country' => $this->str($row['country'] ?? null, 60),
                'committed_to' => $committed !== '' ? $committed : null,
                'recruit_type' => (string) $this->str($row['recruitType'] ?? 'HighSchool', 20),
            ];
        }

        return [$out, ''];
    }

    /**
     * Class rankings.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function teamRecruiting(int $year): array
    {
        [$rows, $error] = $this->fetch('/recruiting/teams', ['year' => $year]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['team'] ?? '')) === '') {
                continue;
            }

            $out[] = [
                'team' => trim((string) $row['team']),
                'year' => (int) ($row['year'] ?? $year),
                'rank' => $this->int($row['rank'] ?? null),
                'points' => isset($row['points']) ? round((float) $row['points'], 2) : null,
            ];
        }

        return [$out, ''];
    }

    /**
     * The transfer portal.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function portal(int $year): array
    {
        [$rows, $error] = $this->fetch('/player/portal', ['year' => $year]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $first = (string) $this->str($row['firstName'] ?? null, 100);
            $last = (string) $this->str($row['lastName'] ?? null, 100);
            $name = trim($first . ' ' . $last);

            if ($name === '') {
                continue;
            }

            $out[] = [
                'season' => (int) ($row['season'] ?? $year),
                'name' => $name,
                'first_name' => $first !== '' ? $first : null,
                'last_name' => $last !== '' ? $last : null,
                'position' => $this->str($row['position'] ?? null, 8),
                'origin' => $this->str($row['origin'] ?? null, 190),
                'destination' => $this->str($row['destination'] ?? null, 190),
                'transfer_date' => $this->date($row['transferDate'] ?? null),
                'rating' => isset($row['rating']) && $row['rating'] !== null
                    ? round((float) $row['rating'], 4)
                    : null,
                'stars' => $this->int($row['stars'] ?? null),
                'eligibility' => $this->str($row['eligibility'] ?? null, 40),
            ];
        }

        return [$out, ''];
    }

    /**
     * Season records.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function records(int $year): array
    {
        [$rows, $error] = $this->fetch('/records', ['year' => $year]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['team'] ?? '')) === '') {
                continue;
            }

            $out[] = [
                'team' => trim((string) $row['team']),
                'season' => (int) ($row['year'] ?? $year),
                'wins' => (int) ($row['total']['wins'] ?? 0),
                'losses' => (int) ($row['total']['losses'] ?? 0),
                'ties' => (int) ($row['total']['ties'] ?? 0),
                'conf_wins' => (int) ($row['conferenceGames']['wins'] ?? 0),
                'conf_losses' => (int) ($row['conferenceGames']['losses'] ?? 0),
            ];
        }

        return [$out, ''];
    }

    /**
     * Blue-chip talent composite.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function talent(int $year): array
    {
        [$rows, $error] = $this->fetch('/talent', ['year' => $year]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['team'] ?? '')) === '') {
                continue;
            }

            $out[] = [
                'team' => trim((string) $row['team']),
                'season' => (int) ($row['year'] ?? $year),
                'talent' => isset($row['talent']) ? round((float) $row['talent'], 2) : null,
            ];
        }

        return [$out, ''];
    }

    /**
     * SP+ ratings.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function ratings(int $year): array
    {
        [$rows, $error] = $this->fetch('/ratings/sp', ['year' => $year]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row) || trim((string) ($row['team'] ?? '')) === '') {
                continue;
            }

            $out[] = [
                'team' => trim((string) $row['team']),
                'season' => (int) ($row['year'] ?? $year),
                'sp_rating' => isset($row['rating']) ? round((float) $row['rating'], 2) : null,
                'sp_rank' => $this->int($row['ranking'] ?? null),
            ];
        }

        return [$out, ''];
    }

    /**
     * One GET, counted and classified.
     *
     * @param array<string, string|int> $query
     * @return array{0: list<mixed>, 1: string}
     */
    private function fetch(string $path, array $query): array
    {
        $key = $this->settings->cfbdKey();

        if ($key === '') {
            return [[], 'not_configured'];
        }

        if (!$this->budget->may()) {
            return [[], $this->budget->reason()];
        }

        [$status, $body, $headers] = $this->http->getJson(
            self::BASE . $path,
            $query,
            ['Authorization' => 'Bearer ' . $key],
        );

        /* 🚨 Counted whether it worked or not — a 401 still spends a call. */
        $this->budget->record($headers);

        if ($status === 0) {
            return [[], 'unreachable'];
        }

        if ($status === 401 || $status === 403) {
            return [[], 'invalid_key'];
        }

        if ($status === 429) {
            return [[], 'rate_limited'];
        }

        if ($status !== 200) {
            return [[], 'error_' . $status];
        }

        return [array_is_list($body) ? $body : [], ''];
    }

    private function str(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }

    private function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * The class a player is in: freshman through super senior, 1 to 5.
     *
     * 🚨 **CFBD's roster `year` is not always the class.** On older rosters it
     * carries the SEASON instead — a 2005 row comes back as `"year":2005` — and
     * some current rows do the same. Stored straight into a `tinyint` that is
     * an out-of-range error which fails the whole chunk, and stored into
     * anything wider it is a roster listing people as being in their 2025th
     * year.
     *
     * Anything outside 1-5 is not a class, so it is not recorded as one. The
     * roster page prints nothing in that column rather than a wrong answer.
     */
    private function classYear(mixed $value): ?int
    {
        $year = $this->int($value);

        return $year !== null && $year >= 1 && $year <= 5 ? $year : null;
    }

    /** CFBD sends ISO-8601 with a Z; MySQL wants a plain datetime. */
    private function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $time = strtotime((string) $value);

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }
}
