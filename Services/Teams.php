<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

use Convoro\Engine\Database\Connection;

/**
 * What the conference index and a school page read.
 *
 * Every method here is a SELECT against the mirror. Nothing reaches for
 * CollegeFootballData — see the note on the module.
 */
final class Teams
{
    /**
     * How a roster is grouped on a school page.
     *
     * 🚨 Ordered, and the order is the point: a roster listed alphabetically by
     * position puts the punter above the quarterback. Anything CFBD lists that
     * is not below falls into "Other" rather than being dropped, because a
     * position that has not been seen before is not a reason to hide a player.
     *
     * @var array<string, list<string>>
     */
    public const POSITION_GROUPS = [
        'offense' => ['QB', 'RB', 'FB', 'WR', 'TE', 'OL', 'OT', 'OG', 'C'],
        'defense' => ['DL', 'DE', 'DT', 'NT', 'LB', 'ILB', 'OLB', 'EDGE', 'CB', 'S', 'DB'],
        'specialists' => ['K', 'P', 'LS', 'PK', 'ATH'],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Every FBS team, grouped by conference.
     *
     * 🚨 Conferences are ordered by SIZE and then name rather than by a
     * hardcoded list of the power leagues. A fixed order is a maintenance
     * problem that comes due every time realignment happens — which lately is
     * every year — and it silently drops any conference nobody thought of.
     *
     * @return list<array{conference: string, teams: list<array<string, mixed>>}>
     */
    public function byConference(): array
    {
        $rows = $this->db->select(
            'SELECT `id`, `school`, `slug`, `mascot`, `abbreviation`, `conference`,'
            . ' `logo`, `logo_dark`, `color`, `forum_id`'
            . ' FROM `' . $this->db->prefixed('almanac_teams') . '`'
            . ' ORDER BY `conference` ASC, `school` ASC'
        );

        $grouped = [];

        foreach ($rows as $row) {
            $conference = (string) ($row['conference'] ?? '');
            $grouped[$conference === '' ? 'Independent' : $conference][] = $row;
        }

        uksort($grouped, static function (string $a, string $b) use ($grouped): int {
            return count($grouped[$b]) <=> count($grouped[$a]) ?: strcmp($a, $b);
        });

        $out = [];

        foreach ($grouped as $conference => $teams) {
            $out[] = ['conference' => $conference, 'teams' => $teams];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `' . $this->db->prefixed('almanac_teams') . '` WHERE `slug` = ?',
            [$slug],
        );
    }

    /**
     * A team's season line — record, ratings, talent.
     *
     * @return array<string, mixed>|null
     */
    public function season(int $teamId, int $season): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `' . $this->db->prefixed('almanac_team_seasons') . '`'
            . ' WHERE `team_id` = ? AND `season` = ?',
            [$teamId, $season],
        );
    }

    /**
     * Every season Almanac holds for this team, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function seasons(int $teamId): array
    {
        return $this->db->select(
            'SELECT * FROM `' . $this->db->prefixed('almanac_team_seasons') . '`'
            . ' WHERE `team_id` = ? ORDER BY `season` DESC',
            [$teamId],
        );
    }

    /**
     * The roster, grouped for display.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function roster(int $teamId, int $season): array
    {
        $players = $this->db->prefixed('almanac_players');
        $seasons = $this->db->prefixed('almanac_player_seasons');

        /*
         * Read through the APPEARANCE rather than `players.team_id`, so an
         * older season shows the roster as it was rather than as it is now.
         * The player row carries who he is today; this table carries where he
         * was then.
         */
        $rows = $this->db->select(
            "SELECT p.`id`, p.`cfbd_id`, p.`name`, p.`slug`, p.`recruit_id`,"
            . " s.`position`, s.`jersey`, s.`height`, s.`weight`, s.`class_year`,"
            . " p.`home_city`, p.`home_state`"
            . " FROM `{$seasons}` s"
            . " INNER JOIN `{$players}` p ON p.`cfbd_id` = s.`player_id`"
            . " WHERE s.`team_id` = ? AND s.`season` = ?"
            . " ORDER BY s.`jersey` IS NULL, s.`jersey` ASC, p.`name` ASC",
            [$teamId, $season],
        );

        $grouped = ['offense' => [], 'defense' => [], 'specialists' => [], 'other' => []];

        foreach ($rows as $row) {
            $grouped[$this->groupFor($row['position'] ?? null)][] = $row;
        }

        /*
         * Within a group, by the order the position appears in the list above,
         * then by jersey. This is what makes a roster read like a roster.
         */
        foreach (self::POSITION_GROUPS as $group => $order) {
            usort($grouped[$group], static function (array $a, array $b) use ($order): int {
                $ai = array_search((string) ($a['position'] ?? ''), $order, true);
                $bi = array_search((string) ($b['position'] ?? ''), $order, true);

                return ($ai === false ? 99 : $ai) <=> ($bi === false ? 99 : $bi)
                    ?: ((int) ($a['jersey'] ?? 999) <=> (int) ($b['jersey'] ?? 999));
            });
        }

        return array_filter($grouped, static fn (array $g): bool => $g !== []);
    }

    /** Which display group a position belongs to. */
    public function groupFor(?string $position): string
    {
        $position = strtoupper(trim((string) $position));

        foreach (self::POSITION_GROUPS as $group => $positions) {
            if (in_array($position, $positions, true)) {
                return $group;
            }
        }

        return 'other';
    }

    /**
     * This team's incoming class.
     *
     * @return list<array<string, mixed>>
     */
    public function recruitingClass(int $teamId, int $year): array
    {
        return $this->db->select(
            'SELECT * FROM `' . $this->db->prefixed('almanac_recruits') . '`'
            . ' WHERE `committed_team_id` = ? AND `year` = ?'
            . ' ORDER BY `ranking` IS NULL, `ranking` ASC, `stars` DESC, `name` ASC',
            [$teamId, $year],
        );
    }

    /** @return array<string, mixed>|null */
    public function classRank(int $teamId, int $year): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `' . $this->db->prefixed('almanac_team_recruiting') . '`'
            . ' WHERE `team_id` = ? AND `year` = ?',
            [$teamId, $year],
        );
    }

    /**
     * Who has arrived and who has left through the portal.
     *
     * @return array{in: list<array<string, mixed>>, out: list<array<string, mixed>>}
     */
    public function transfers(int $teamId, int $season): array
    {
        $table = $this->db->prefixed('almanac_transfers');

        return [
            'in' => $this->db->select(
                "SELECT * FROM `{$table}` WHERE `destination_team_id` = ? AND `season` = ?"
                . ' ORDER BY `stars` DESC, `name` ASC',
                [$teamId, $season],
            ),
            'out' => $this->db->select(
                "SELECT * FROM `{$table}` WHERE `origin_team_id` = ? AND `season` = ?"
                . ' ORDER BY `stars` DESC, `name` ASC',
                [$teamId, $season],
            ),
        ];
    }

    /**
     * Recent discussion in this team's forum.
     *
     * 🚨 **The caller must have checked `maySee()` on this forum first.** This
     * method filters hidden and soft-deleted topics and nothing else — it has
     * no idea who is asking. Convoro has already shipped one bug where an
     * endpoint served restricted forums to anybody who asked, and a "recent
     * threads" panel on a public page is exactly the shape that repeats it.
     *
     * @return list<array<string, mixed>>
     */
    public function forumTopics(int $forumId, int $limit = 6): array
    {
        if ($forumId < 1) {
            return [];
        }

        try {
            return $this->db->select(
                'SELECT `id`, `title`, `slug`, `post_count`, `last_post_at`'
                . ' FROM `' . $this->db->prefixed('topics') . '`'
                . ' WHERE `forum_id` = ? AND `is_hidden` = 0 AND `deleted_at` IS NULL'
                . ' ORDER BY `last_post_at` DESC'
                . ' LIMIT ' . max(1, min(20, $limit)),
                [$forumId],
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
