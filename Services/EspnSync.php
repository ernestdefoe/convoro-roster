<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

use Convoro\Engine\Database\Connection;
use Convoro\Extensions\Almanac\Services\Leagues\League;
use Convoro\Extensions\Almanac\Services\Leagues\Leagues;
use Convoro\Extensions\Almanac\Services\Sources\EspnRoster;

/**
 * Teams and rosters for every league that is not college football.
 *
 * 🚨 Deliberately a SECOND service beside `Sync` rather than a rewrite of it.
 * That one walks an ordered, resumable plan across a THOUSAND-CALL MONTHLY
 * BUDGET — the single fact that shapes the whole college side. ESPN charges
 * nothing and needs no key, so a resumable cursor and a budget guard would be
 * machinery serving a constraint that is not there. What this needs instead is
 * a plain per-run ceiling, because one roster is one call and a league is
 * thirty-two of them.
 *
 * 🚨 It writes the SAME tables. A club is an `almanac_teams` row and a player
 * is an `almanac_players` row whichever league they are in, which is what lets
 * every existing screen keep working.
 */
final class EspnSync
{
    /**
     * 🚨 Rosters fetched per run. A league is thirty-odd clubs and a site might
     * follow five; without a ceiling the first tick after installing would fire
     * a hundred and sixty outbound calls in a minute, which has taken a site on
     * this stack down before. A club whose roster is a day old is invisible; a
     * queue worker killed by its own traffic is not.
     */
    public const ROSTERS_PER_RUN = 12;

    /** How long a club's roster is considered fresh enough to skip. */
    public const REFRESH_HOURS = 24;

    public function __construct(
        private readonly Connection $db,
        private readonly EspnRoster $espn,
        private readonly Settings $settings,
        private readonly Leagues $leagues = new Leagues(),
    ) {
    }

    /**
     * @return array<string, mixed> a summary, for the log and for tests
     */
    public function run(): array
    {
        if (!$this->settings->enabled()) {
            return ['skipped' => 'off'];
        }

        $following = $this->following();

        if ($following === []) {
            return ['skipped' => 'no espn leagues'];
        }

        $summary = ['leagues' => 0, 'teams' => 0, 'rosters' => 0, 'players' => 0];

        foreach ($following as $key) {
            $league = $this->leagues->get($key);

            if (!$this->espn->supports($league)) {
                continue;
            }

            $summary['leagues']++;
            $summary['teams'] += $this->teams($league);
        }

        /*
         * 🚨 Rosters are fetched ACROSS leagues in one ordered pass, oldest
         * first, rather than league by league. Per league, a site following
         * five would spend every run's whole ceiling on the first one and the
         * fifth would never be fetched at all.
         */
        [$rosters, $players] = $this->rosters();

        $summary['rosters'] = $rosters;
        $summary['players'] = $players;

        return $summary;
    }

    /* --------------------------------------------------------------- teams */

    private function teams(League $league): int
    {
        $teams = $this->espn->teams($league);

        if ($teams === []) {
            return 0;
        }

        $divisions = $this->espn->divisions($league);
        $table = $this->db->prefixed('almanac_teams');
        $now = date('Y-m-d H:i:s');
        $written = 0;

        foreach ($teams as $team) {
            $existing = $this->db->selectOne(
                "SELECT `id` FROM `{$table}` WHERE `league` = ? AND `external_id` = ?",
                [$league->key, $team['external_id']],
            );

            $values = [
                'school' => mb_substr((string) $team['school'], 0, 190),
                'mascot' => mb_substr((string) $team['mascot'], 0, 120),
                'abbreviation' => mb_substr((string) $team['abbreviation'], 0, 16),
                'conference' => $divisions[$team['external_id']] ?? '',
                'classification' => $league->key,
                'color' => mb_substr((string) $team['color'], 0, 16),
                'alt_color' => mb_substr((string) $team['alt_color'], 0, 16),
                'logo' => mb_substr((string) $team['logo'], 0, 255),
                'logo_dark' => mb_substr((string) $team['logo_dark'], 0, 255),
                'updated_at' => $now,
            ];

            if ($existing !== null) {
                /*
                 * 🚨 `slug` is never updated. It is in URLs people have shared,
                 * and the college side makes the same promise.
                 */
                $this->db->table('almanac_teams')->where('id', $existing['id'])->updateAll($values);
                $written++;

                continue;
            }

            $this->db->table('almanac_teams')->insertGetId($values + [
                'league' => $league->key,
                'external_id' => $team['external_id'],
                /*
                 * 🚨 The slug carries the league. Two leagues can hold a club
                 * of the same name, `slug` is unique across the table, and the
                 * loser of that collision would silently become the winner's
                 * page. College keeps its bare slug because those URLs exist.
                 */
                'slug' => $this->slug($league, (string) ($team['slug'] ?: $team['school'])),
                'created_at' => $now,
            ]);

            $written++;
        }

        return $written;
    }

    /* ------------------------------------------------------------- rosters */

    /** @return array{0: int, 1: int} rosters fetched, players written */
    private function rosters(): array
    {
        $table = $this->db->prefixed('almanac_teams');
        $stale = date('Y-m-d H:i:s', time() - (self::REFRESH_HOURS * 3600));

        /*
         * 🚨 Oldest first, and `roster_at IS NULL` sorts first of all — so a
         * newly followed league fills in before anything is refreshed. A site
         * that has just added the NBA wants thirty rosters, not one club's
         * update.
         */
        $due = $this->db->select(
            "SELECT `id`, `league`, `external_id`
               FROM `{$table}`
              WHERE `league` <> ? AND `external_id` IS NOT NULL
                AND (`roster_at` IS NULL OR `roster_at` < ?)
           ORDER BY `roster_at` IS NOT NULL, `roster_at` ASC
              LIMIT " . self::ROSTERS_PER_RUN,
            [Leagues::DEFAULT, $stale],
        );

        $fetched = 0;
        $players = 0;

        foreach ($due as $team) {
            $league = $this->leagues->get($team['league']);
            $roster = $this->espn->roster($league, (string) $team['external_id']);

            /*
             * 🚨 Stamped even when the roster came back EMPTY. ESPN assembles
             * this endpoint from its own upstream calls and a single athlete
             * with a missing record takes a whole club's roster down with a
             * 404 — seen live on one NFL club while the other thirty-one
             * answered. Without the stamp that club would be retried first on
             * every run for ever, and no other club would ever be reached.
             */
            $this->db->table('almanac_teams')
                ->where('id', $team['id'])
                ->updateAll(['roster_at' => date('Y-m-d H:i:s')]);

            $fetched++;

            if ($roster === []) {
                continue;
            }

            $players += $this->players($league, (int) $team['id'], $roster);
        }

        return [$fetched, $players];
    }

    /**
     * @param list<array<string, mixed>> $roster
     */
    private function players(League $league, int $teamId, array $roster): int
    {
        $table = $this->db->prefixed('almanac_players');
        $now = date('Y-m-d H:i:s');
        $written = 0;

        foreach ($roster as $player) {
            $values = [
                'name' => mb_substr((string) $player['name'], 0, 190),
                'first_name' => mb_substr((string) $player['first_name'], 0, 100),
                'last_name' => mb_substr((string) $player['last_name'], 0, 100),
                'position' => mb_substr((string) $player['position'], 0, 8),
                'position_group' => (string) $player['position_group'],
                'jersey' => $player['jersey'],
                'height' => $player['height'],
                'weight' => $player['weight'],
                'home_city' => mb_substr((string) $player['home_city'], 0, 120),
                'home_state' => mb_substr((string) $player['home_state'], 0, 16),
                'home_country' => mb_substr((string) $player['home_country'], 0, 60),
                'team_id' => $teamId,
                'updated_at' => $now,
            ];

            $existing = $this->db->selectOne(
                "SELECT `id` FROM `{$table}` WHERE `league` = ? AND `external_id` = ?",
                [$league->key, $player['external_id']],
            );

            if ($existing !== null) {
                $this->db->table('almanac_players')->where('id', $existing['id'])->updateAll($values);
                $written++;

                continue;
            }

            $this->db->table('almanac_players')->insertGetId($values + [
                'league' => $league->key,
                'external_id' => $player['external_id'],
                'slug' => $this->slug($league, (string) $player['name']) . '-' . $player['external_id'],
                'created_at' => $now,
            ]);

            $written++;
        }

        return $written;
    }

    /* -------------------------------------------------------------- naming */

    /** @return list<string> the league keys this site follows */
    private function following(): array
    {
        $raw = trim($this->settings->get('almanac_leagues'));

        if ($raw === '') {
            return [];
        }

        $out = [];

        foreach (explode(',', $raw) as $key) {
            $key = trim($key);

            /*
             * 🚨 College football is never in this list even if somebody puts
             * it there. It is CollegeFootballData's, and syncing it from ESPN
             * would overwrite forty thousand players' worth of detail ESPN does
             * not have with a current roster.
             */
            if ($key !== '' && $key !== Leagues::DEFAULT && $this->leagues->has($key)) {
                $out[$key] = $key;
            }
        }

        return array_values($out);
    }

    private function slug(League $league, string $value): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($value)), '-');
        $slug = $slug === '' ? substr(md5($value), 0, 10) : $slug;

        return mb_substr($league->key . '-' . $slug, 0, 200);
    }
}
