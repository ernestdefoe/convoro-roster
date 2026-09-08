<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

use Convoro\Engine\Database\Connection;

/**
 * Everything that writes a row.
 *
 * 🚨 **Every write is an upsert, and that is what makes the sync safe to run
 * twice.** A run can stop anywhere — the budget guard is designed to stop it
 * anywhere — so the next run repeats whatever the last one was part-way
 * through. `INSERT ... ON DUPLICATE KEY UPDATE` against the natural keys
 * declared in the migration means a repeat is a no-op rather than a duplicate,
 * and no step needs to know whether it has run before.
 *
 * 🚨 **Nothing here deletes.** A provider that answers with a short list —
 * mid-update, or during an outage that returns 200 and an empty body — must not
 * be able to empty a roster. Rows go stale rather than missing, and a page can
 * say how old they are. The one exception is `replaceSeasonStats()`, which is
 * scoped tightly enough to say why in its own comment.
 *
 * 🚨 **Rows are written in CHUNKS.** One season of rosters is 30,000 rows and a
 * category of season stats can be 20,000; a single statement with that many
 * placeholders exceeds `max_allowed_packet` and MySQL's 65,535-placeholder
 * ceiling, and the failure arrives as a truncated write rather than an error.
 */
final class Store
{
    /**
     * Rows per statement. 400 rows of the widest table here stays well inside
     * both the placeholder ceiling and a default `max_allowed_packet`.
     */
    private const CHUNK = 400;

    /** @var array<string, int>|null school name (lowercased) => team id */
    private ?array $teamIds = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Teams                                                               */
    /* ------------------------------------------------------------------ */

    /** @param list<array<string, mixed>> $rows */
    public function upsertTeams(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $prepared = [];

        foreach ($rows as $row) {
            $prepared[] = [
                'cfbd_id' => $row['cfbd_id'],
                'espn_id' => $row['espn_id'],
                'school' => $row['school'],
                'slug' => $this->slug((string) $row['school']),
                'mascot' => $row['mascot'],
                'abbreviation' => $row['abbreviation'],
                'conference' => $row['conference'],
                'division' => $row['division'],
                'classification' => $row['classification'],
                'color' => $row['color'],
                'alt_color' => $row['alt_color'],
                'logo' => $row['logo'],
                'logo_dark' => $row['logo_dark'],
                'venue' => $row['venue'],
                'venue_city' => $row['venue_city'],
                'venue_state' => $row['venue_state'],
            ];
        }

        $written = $this->upsert('almanac_teams', $prepared, [
            'espn_id', 'school', 'mascot', 'abbreviation', 'conference', 'division',
            'classification', 'color', 'alt_color', 'logo', 'logo_dark',
            'venue', 'venue_city', 'venue_state',
            /* 🚨 `slug` is NOT updated. It is in URLs people have shared. */
        ]);

        $this->teamIds = null;

        return $written;
    }

    /**
     * Point each team at its forum, using Picks' mapping where there is one.
     *
     * 🚨 Wrapped, because Roster does not depend on Picks. A site without it
     * has no `picks_teams` table, and the whole feature is a panel that does
     * not render rather than an error on every school page.
     */
    public function resolveForums(): int
    {
        $teams = $this->db->prefixed('almanac_teams');
        $picks = $this->db->prefixed('picks_teams');

        try {
            return $this->db->update(
                "UPDATE `{$teams}` a"
                . " INNER JOIN `{$picks}` p ON p.`cfbd_id` = a.`cfbd_id`"
                . " SET a.`forum_id` = p.`forum_id`"
                . " WHERE p.`forum_id` > 0 AND a.`forum_id` <> p.`forum_id`"
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string, int> lowercased school name => id */
    public function teamIds(): array
    {
        if ($this->teamIds !== null) {
            return $this->teamIds;
        }

        $this->teamIds = [];

        foreach ($this->db->table('almanac_teams')->get() as $row) {
            $this->teamIds[mb_strtolower((string) $row['school'])] = (int) $row['id'];
        }

        return $this->teamIds;
    }

    public function teamId(?string $school): int
    {
        if ($school === null || trim($school) === '') {
            return 0;
        }

        return $this->teamIds()[mb_strtolower(trim($school))] ?? 0;
    }

    /* ------------------------------------------------------------------ */
    /* Players                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * A season's rosters: the people, and their appearance that year.
     *
     * 🚨 Only rows whose team Roster carries are written. `/roster` answers
     * for every division — 315 teams — and the other 180 are programmes no page
     * here can link to.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: int, 1: int} players touched, appearances written
     */
    public function upsertRoster(array $rows, int $season): array
    {
        $players = [];
        $appearances = [];

        foreach ($rows as $row) {
            $teamId = $this->teamId($row['team'] ?? null);

            if ($teamId === 0) {
                continue;
            }

            $cfbdId = (int) $row['cfbd_id'];

            $players[$cfbdId] = [
                'cfbd_id' => $cfbdId,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'name' => $row['name'],
                'slug' => $this->playerSlug((string) $row['name'], $cfbdId),
                'position' => $row['position'],
                'jersey' => $row['jersey'],
                'height' => $row['height'],
                'weight' => $row['weight'],
                'home_city' => $row['home_city'],
                'home_state' => $row['home_state'],
                'home_country' => $row['home_country'],
                'team_id' => $teamId,
                'class_year' => $row['class_year'],
                'recruit_id' => (int) ($row['recruit_id'] ?? 0),
            ];

            $appearances[] = [
                'player_id' => $cfbdId,
                'team_id' => $teamId,
                'season' => $season,
                'position' => $row['position'],
                'jersey' => $row['jersey'],
                'height' => $row['height'],
                'weight' => $row['weight'],
                'class_year' => $row['class_year'],
            ];
        }

        if ($players === []) {
            return [0, 0];
        }

        /*
         * 🚨 The identity columns are updated only from the MOST RECENT season
         * the sync has walked, and the plan walks newest first. Without that
         * rule a backfill reaching 2021 would overwrite a senior's current team
         * and jersey with the ones he had as a freshman, and the roster page
         * would quietly fill with players listed at the wrong school.
         */
        $written = $this->upsert('almanac_players', array_values($players), [
            'first_name', 'last_name', 'name', 'position', 'jersey', 'height',
            'weight', 'home_city', 'home_state', 'home_country', 'team_id',
            'class_year', 'recruit_id',
        ]);

        $seasons = $this->upsert('almanac_player_seasons', $appearances, [
            'position', 'jersey', 'height', 'weight', 'class_year',
        ]);

        return [$written, $seasons];
    }

    /**
     * Identity columns, but only where the sync has not already written a more
     * recent season. Used for every season after the first.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function upsertRosterHistoryOnly(array $rows, int $season): int
    {
        $appearances = [];
        $stubs = [];

        foreach ($rows as $row) {
            $teamId = $this->teamId($row['team'] ?? null);

            if ($teamId === 0) {
                continue;
            }

            $cfbdId = (int) $row['cfbd_id'];

            /*
             * A player who appears only in an older season still needs a row,
             * or his appearances point at nothing. Inserted with his details
             * from that year and NEVER updated afterwards — see the ignore
             * list below.
             */
            $stubs[$cfbdId] = [
                'cfbd_id' => $cfbdId,
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'name' => $row['name'],
                'slug' => $this->playerSlug((string) $row['name'], $cfbdId),
                'position' => $row['position'],
                'jersey' => $row['jersey'],
                'height' => $row['height'],
                'weight' => $row['weight'],
                'home_city' => $row['home_city'],
                'home_state' => $row['home_state'],
                'home_country' => $row['home_country'],
                'team_id' => $teamId,
                'class_year' => $row['class_year'],
                'recruit_id' => (int) ($row['recruit_id'] ?? 0),
            ];

            $appearances[] = [
                'player_id' => $cfbdId,
                'team_id' => $teamId,
                'season' => $season,
                'position' => $row['position'],
                'jersey' => $row['jersey'],
                'height' => $row['height'],
                'weight' => $row['weight'],
                'class_year' => $row['class_year'],
            ];
        }

        if ($stubs !== []) {
            /*
             * 🚨 `recruit_id` IS still filled in from an older roster, because
             * CFBD populates it unevenly across years and a player's high
             * school does not change. Everything else about who he is now comes
             * from the newest season only.
             */
            $this->upsert('almanac_players', array_values($stubs), ['recruit_id']);
        }

        return $appearances === []
            ? 0
            : $this->upsert('almanac_player_seasons', $appearances, [
                'position', 'jersey', 'height', 'weight', 'class_year',
            ]);
    }

    /**
     * Fill in first and last season from the appearances actually stored.
     *
     * Cheaper and more honest than tracking it on the way in: it describes what
     * Roster HOLDS, so a player page never claims a career that reaches back
     * further than the seasons this site synced.
     */
    public function recomputeCareerSpans(): int
    {
        $players = $this->db->prefixed('almanac_players');
        $seasons = $this->db->prefixed('almanac_player_seasons');

        return $this->db->update(
            "UPDATE `{$players}` p"
            . " INNER JOIN ("
            . "   SELECT `player_id`, MIN(`season`) AS `first`, MAX(`season`) AS `last`"
            . "   FROM `{$seasons}` GROUP BY `player_id`"
            . " ) s ON s.`player_id` = p.`cfbd_id`"
            . " SET p.`first_season` = s.`first`, p.`last_season` = s.`last`"
            /* NULL-safe both ways: the first run has NULLs on the left, and
             * `<>` alone would match nothing and update no rows. */
            . " WHERE NOT (p.`first_season` <=> s.`first`)"
            . " OR NOT (p.`last_season` <=> s.`last`)"
        );
    }

    /* ------------------------------------------------------------------ */
    /* Stats                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function upsertSeasonStats(array $rows, int $season): int
    {
        $prepared = [];

        foreach ($rows as $row) {
            if (($row['stat_type'] ?? '') === '' || ($row['category'] ?? '') === '') {
                continue;
            }

            $prepared[] = [
                'player_id' => (int) $row['cfbd_id'],
                'team_id' => $this->teamId($row['team'] ?? null),
                'season' => (int) ($row['season'] ?? $season),
                'category' => $row['category'],
                'stat_type' => $row['stat_type'],
                'stat' => $row['stat'],
            ];
        }

        return $prepared === []
            ? 0
            : $this->upsert('almanac_player_stats', $prepared, ['team_id', 'stat']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function upsertGameStats(array $rows): int
    {
        $prepared = [];

        foreach ($rows as $row) {
            if (($row['stat_type'] ?? '') === '' || ($row['category'] ?? '') === '') {
                continue;
            }

            $prepared[] = [
                'player_id' => (int) $row['cfbd_id'],
                'game_id' => (int) $row['game_id'],
                'season' => (int) $row['season'],
                'week' => (int) $row['week'],
                'season_type' => $row['season_type'],
                'team_id' => $this->teamId($row['team'] ?? null),
                'opponent_id' => $this->teamId($row['opponent'] ?? null),
                'home_away' => $row['home_away'],
                'category' => $row['category'],
                'stat_type' => $row['stat_type'],
                'stat' => $row['stat'],
            ];
        }

        return $prepared === []
            ? 0
            : $this->upsert('almanac_game_stats', $prepared, [
                'team_id', 'opponent_id', 'home_away', 'stat', 'week', 'season_type',
            ]);
    }

    /* ------------------------------------------------------------------ */
    /* Recruiting and the portal                                           */
    /* ------------------------------------------------------------------ */

    /** @param list<array<string, mixed>> $rows */
    public function upsertRecruits(array $rows): int
    {
        $prepared = [];

        foreach ($rows as $row) {
            $prepared[] = [
                'cfbd_id' => (int) $row['cfbd_id'],
                'athlete_id' => $row['athlete_id'],
                'year' => (int) $row['year'],
                'ranking' => $row['ranking'],
                'name' => $row['name'],
                'slug' => $this->playerSlug((string) $row['name'], (int) $row['cfbd_id']),
                'position' => $row['position'],
                'height' => $row['height'],
                'weight' => $row['weight'],
                'stars' => $row['stars'],
                'rating' => $row['rating'],
                'high_school' => $row['high_school'],
                'city' => $row['city'],
                'state' => $row['state'],
                'country' => $row['country'],
                'committed_team_id' => $this->teamId($row['committed_to'] ?? null),
                'committed_to' => $row['committed_to'],
                'recruit_type' => $row['recruit_type'],
            ];
        }

        return $prepared === []
            ? 0
            : $this->upsert('almanac_recruits', $prepared, [
                'athlete_id', 'ranking', 'position', 'height', 'weight', 'stars',
                'rating', 'high_school', 'city', 'state', 'country',
                'committed_team_id', 'committed_to', 'recruit_type',
            ]);
    }

    /**
     * Tie current players to the class they came out of.
     *
     * Two routes, and both are needed. The roster's own `recruitIds` is the
     * reliable one but CFBD fills it unevenly; the recruit's `athleteId` covers
     * a different slice. Neither alone links everybody.
     *
     * 🚨 Name matching is deliberately NOT attempted. Two players called
     * J. Williams in the same class is not rare, and a wrong link puts another
     * man's high school on somebody's page — worse than an empty panel.
     */
    public function linkRecruits(): int
    {
        $players = $this->db->prefixed('almanac_players');
        $recruits = $this->db->prefixed('almanac_recruits');

        $linked = $this->db->update(
            "UPDATE `{$players}` p"
            . " INNER JOIN `{$recruits}` r ON r.`athlete_id` = p.`cfbd_id`"
            . " SET p.`recruit_id` = r.`cfbd_id`"
            . " WHERE p.`recruit_id` = 0 AND r.`athlete_id` IS NOT NULL"
        );

        return $linked;
    }

    /** @param list<array<string, mixed>> $rows */
    public function upsertTeamRecruiting(array $rows): int
    {
        $prepared = [];

        foreach ($rows as $row) {
            $teamId = $this->teamId($row['team'] ?? null);

            if ($teamId === 0) {
                continue;
            }

            $prepared[] = [
                'team_id' => $teamId,
                'year' => (int) $row['year'],
                'rank' => $row['rank'],
                'points' => $row['points'],
            ];
        }

        return $prepared === []
            ? 0
            : $this->upsert('almanac_team_recruiting', $prepared, ['rank', 'points']);
    }

    /** @param list<array<string, mixed>> $rows */
    public function upsertTransfers(array $rows): int
    {
        $prepared = [];

        foreach ($rows as $row) {
            $prepared[] = [
                'season' => (int) $row['season'],
                'name' => $row['name'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'position' => $row['position'],
                'origin_team_id' => $this->teamId($row['origin'] ?? null),
                'destination_team_id' => $this->teamId($row['destination'] ?? null),
                /* 🚨 Part of the natural key, so it must never be null — the
                 * unique index would stop constraining and every run would
                 * insert the board again. */
                'origin' => (string) ($row['origin'] ?? ''),
                'destination' => $row['destination'],
                'transfer_date' => $row['transfer_date'],
                'rating' => $row['rating'],
                'stars' => $row['stars'],
                'eligibility' => $row['eligibility'],
            ];
        }

        return $prepared === []
            ? 0
            : $this->upsert('almanac_transfers', $prepared, [
                'first_name', 'last_name', 'position', 'origin_team_id',
                'destination_team_id', 'destination', 'transfer_date',
                'rating', 'stars', 'eligibility',
            ]);
    }

    /* ------------------------------------------------------------------ */
    /* Team seasons                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Records, talent and ratings all describe one team-season, and each
     * arrives from a different endpoint. Written through one method so the row
     * is created by whichever lands first.
     *
     * @param list<array<string, mixed>> $rows each with team + season
     * @param list<string> $columns the fields this caller is supplying
     */
    public function upsertTeamSeasons(array $rows, array $columns): int
    {
        $prepared = [];

        foreach ($rows as $row) {
            $teamId = $this->teamId($row['team'] ?? null);

            if ($teamId === 0) {
                continue;
            }

            $entry = ['team_id' => $teamId, 'season' => (int) $row['season']];

            foreach ($columns as $column) {
                $entry[$column] = $row[$column] ?? null;
            }

            $prepared[] = $entry;
        }

        return $prepared === [] ? 0 : $this->upsert('almanac_team_seasons', $prepared, $columns);
    }

    /* ------------------------------------------------------------------ */
    /* Plumbing                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Multi-row INSERT ... ON DUPLICATE KEY UPDATE, chunked.
     *
     * @param list<array<string, mixed>> $rows all sharing one column set
     * @param list<string> $update columns to overwrite when the row exists
     */
    private function upsert(string $table, array $rows, array $update): int
    {
        if ($rows === []) {
            return 0;
        }

        $prefixed = $this->db->prefixed($table);
        $columns = array_keys($rows[0]);
        $now = date('Y-m-d H:i:s');

        $columnSql = '`' . implode('`, `', [...$columns, 'created_at', 'updated_at']) . '`';

        $assignments = array_map(
            static fn (string $c): string => "`{$c}` = VALUES(`{$c}`)",
            $update,
        );
        $assignments[] = "`updated_at` = VALUES(`updated_at`)";

        $affected = 0;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $placeholders = [];
            $bindings = [];

            foreach ($chunk as $row) {
                $marks = [];

                foreach ($columns as $column) {
                    $marks[] = '?';
                    $bindings[] = $row[$column] ?? null;
                }

                $marks[] = '?';
                $bindings[] = $now;    // created_at
                $marks[] = '?';
                $bindings[] = $now;    // updated_at

                $placeholders[] = '(' . implode(', ', $marks) . ')';
            }

            $affected += $this->db->insert(
                "INSERT INTO `{$prefixed}` ({$columnSql}) VALUES "
                . implode(', ', $placeholders)
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments),
                $bindings,
            );
        }

        return $affected;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-') ?: 'team';
    }

    /**
     * A player's URL.
     *
     * 🚨 The id is part of it, and has to be. Two men called Mike Williams play
     * FBS football most years, and a slug that was only a name would collide;
     * the loser of that collision is a player page that shows somebody else.
     * The negative ids CFBD synthesises are rendered with an `x` rather than a
     * minus so the URL does not carry a leading dash after the name.
     */
    private function playerSlug(string $name, int $id): string
    {
        return $this->slug($name) . '-' . ($id < 0 ? 'x' . abs($id) : (string) $id);
    }
}
