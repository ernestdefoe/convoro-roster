<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

use Convoro\Engine\Database\Connection;

/**
 * The recruiting board and the portal tracker.
 *
 * 🚨 **Filtering and paging happen in SQL, not in the browser.** A class is
 * around four thousand recruits; the Flarum extension this replaces sent the
 * lot to the page and filtered with JavaScript, which is a four-megabyte
 * response and a filter that does nothing until it has all arrived. Whoever is
 * on a phone in a stadium car park pays for that twice.
 */
final class Recruits
{
    public const PER_PAGE = 60;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The national board for one class.
     *
     * @param array<string, mixed> $filters position, state, stars, team, status, q
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function board(int $year, array $filters = [], int $page = 1): array
    {
        [$where, $bindings] = $this->conditions($year, $filters);

        $recruits = $this->db->prefixed('almanac_recruits');
        $teams = $this->db->prefixed('almanac_teams');

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS `c` FROM `{$recruits}` r WHERE {$where}",
            $bindings,
        )['c'] ?? 0);

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $this->db->select(
            "SELECT r.*, t.`slug` AS `committed_slug`, t.`logo` AS `committed_logo`,"
            . " t.`logo_dark` AS `committed_logo_dark`,"
            /* So the card can link straight through to a player page when the
             * recruit has already enrolled and been synced onto a roster. */
            . " p.`slug` AS `player_slug`"
            . " FROM `{$recruits}` r"
            . " LEFT JOIN `{$teams}` t ON t.`id` = r.`committed_team_id`"
            . " LEFT JOIN `" . $this->db->prefixed('almanac_players') . "` p"
            . "   ON p.`recruit_id` = r.`cfbd_id`"
            . " WHERE {$where}"
            /* Unranked recruits last, then by national rank, then by rating. */
            . " ORDER BY r.`ranking` IS NULL, r.`ranking` ASC,"
            . " r.`rating` DESC, r.`name` ASC"
            . " LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $bindings,
        );

        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Build the WHERE clause once, so the count and the page agree.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function conditions(int $year, array $filters): array
    {
        $where = ['r.`year` = ?'];
        $bindings = [$year];

        if (($filters['position'] ?? '') !== '') {
            $where[] = 'r.`position` = ?';
            $bindings[] = strtoupper((string) $filters['position']);
        }

        if (($filters['state'] ?? '') !== '') {
            $where[] = 'r.`state` = ?';
            $bindings[] = strtoupper((string) $filters['state']);
        }

        if ((int) ($filters['stars'] ?? 0) > 0) {
            $where[] = 'r.`stars` >= ?';
            $bindings[] = (int) $filters['stars'];
        }

        if ((int) ($filters['team'] ?? 0) > 0) {
            $where[] = 'r.`committed_team_id` = ?';
            $bindings[] = (int) $filters['team'];
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status === 'committed') {
            $where[] = 'r.`committed_team_id` > 0';
        } elseif ($status === 'uncommitted') {
            $where[] = 'r.`committed_team_id` = 0';
        }

        $query = trim((string) ($filters['q'] ?? ''));

        if ($query !== '') {
            /*
             * 🚨 Escaped before the wildcards go on. A search for "O'Brien" is
             * fine — it is a binding — but an unescaped `%` or `_` typed into
             * the box would otherwise be a wildcard and quietly return the
             * whole board.
             */
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $where[] = '(r.`name` LIKE ? OR r.`high_school` LIKE ? OR r.`city` LIKE ?)';
            $bindings[] = '%' . $escaped . '%';
            $bindings[] = '%' . $escaped . '%';
            $bindings[] = '%' . $escaped . '%';
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * Which class years the mirror actually holds, newest first.
     *
     * Read from the data rather than computed from the current year, so the
     * year picker never offers a class that would render an empty page.
     *
     * @return list<int>
     */
    public function years(): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT `year` FROM `' . $this->db->prefixed('almanac_recruits') . '`'
            . ' ORDER BY `year` DESC'
        );

        return array_map(static fn (array $r): int => (int) $r['year'], $rows);
    }

    /**
     * Positions present in a class, for the filter dropdown.
     *
     * @return list<string>
     */
    public function positions(int $year): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT `position` FROM `' . $this->db->prefixed('almanac_recruits') . '`'
            . ' WHERE `year` = ? AND `position` IS NOT NULL ORDER BY `position` ASC',
            [$year],
        );

        return array_map(static fn (array $r): string => (string) $r['position'], $rows);
    }

    /**
     * States present in a class, for the filter dropdown.
     *
     * @return list<string>
     */
    public function states(int $year): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT `state` FROM `' . $this->db->prefixed('almanac_recruits') . '`'
            . ' WHERE `year` = ? AND `state` IS NOT NULL AND `state` <> \'\''
            . ' ORDER BY `state` ASC',
            [$year],
        );

        return array_map(static fn (array $r): string => (string) $r['state'], $rows);
    }

    /**
     * Team class rankings for a cycle.
     *
     * @return list<array<string, mixed>>
     */
    public function classRankings(int $year, int $limit = 25): array
    {
        $ranking = $this->db->prefixed('almanac_team_recruiting');
        $teams = $this->db->prefixed('almanac_teams');

        return $this->db->select(
            "SELECT tr.*, t.`school`, t.`slug`, t.`logo`, t.`logo_dark`, t.`conference`"
            . " FROM `{$ranking}` tr"
            . " INNER JOIN `{$teams}` t ON t.`id` = tr.`team_id`"
            . " WHERE tr.`year` = ?"
            . " ORDER BY tr.`rank` IS NULL, tr.`rank` ASC"
            . ' LIMIT ' . max(1, min(200, $limit)),
            [$year],
        );
    }

    /**
     * The portal board.
     *
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int, page: int}
     */
    public function portal(int $season, array $filters = [], int $page = 1): array
    {
        $transfers = $this->db->prefixed('almanac_transfers');
        $teams = $this->db->prefixed('almanac_teams');

        $where = ['tr.`season` = ?'];
        $bindings = [$season];

        if (($filters['position'] ?? '') !== '') {
            $where[] = 'tr.`position` = ?';
            $bindings[] = strtoupper((string) $filters['position']);
        }

        if ((int) ($filters['team'] ?? 0) > 0) {
            /* Either direction — somebody following a school wants both. */
            $where[] = '(tr.`origin_team_id` = ? OR tr.`destination_team_id` = ?)';
            $bindings[] = (int) $filters['team'];
            $bindings[] = (int) $filters['team'];
        }

        if (($filters['status'] ?? '') === 'undecided') {
            $where[] = '(tr.`destination` IS NULL OR tr.`destination` = \'\')';
        }

        $query = trim((string) ($filters['q'] ?? ''));

        if ($query !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
            $where[] = 'tr.`name` LIKE ?';
            $bindings[] = '%' . $escaped . '%';
        }

        $clause = implode(' AND ', $where);

        $total = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS `c` FROM `{$transfers}` tr WHERE {$clause}",
            $bindings,
        )['c'] ?? 0);

        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $this->db->select(
            "SELECT tr.*, o.`slug` AS `origin_slug`, o.`logo` AS `origin_logo`,"
            . " o.`logo_dark` AS `origin_logo_dark`,"
            . " d.`slug` AS `destination_slug`, d.`logo` AS `destination_logo`,"
            . " d.`logo_dark` AS `destination_logo_dark`"
            . " FROM `{$transfers}` tr"
            . " LEFT JOIN `{$teams}` o ON o.`id` = tr.`origin_team_id`"
            . " LEFT JOIN `{$teams}` d ON d.`id` = tr.`destination_team_id`"
            . " WHERE {$clause}"
            . " ORDER BY tr.`stars` DESC, tr.`rating` DESC, tr.`name` ASC"
            . ' LIMIT ' . self::PER_PAGE . " OFFSET {$offset}",
            $bindings,
        );

        return ['rows' => $rows, 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Seasons the portal table holds.
     *
     * @return list<int>
     */
    public function portalSeasons(): array
    {
        $rows = $this->db->select(
            'SELECT DISTINCT `season` FROM `' . $this->db->prefixed('almanac_transfers') . '`'
            . ' ORDER BY `season` DESC'
        );

        return array_map(static fn (array $r): int => (int) $r['season'], $rows);
    }
}
