<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

use Convoro\Engine\Database\Connection;

/**
 * What a player page reads.
 *
 * The page has to work for two people who share nothing: a fifth-year starter
 * with four seasons of numbers, and a true freshman who has never taken a snap.
 * The second is why the recruiting join exists — without it his page is a name,
 * a jersey and white space, which is precisely when somebody is looking him up.
 */
final class Players
{
    /**
     * Where ESPN keeps headshots.
     *
     * 🚨 Only valid for a POSITIVE id. CFBD returns an ESPN athlete id where it
     * has one and a synthesised negative id where it does not, and the negative
     * ones resolve to nothing — see `headshot()`.
     */
    private const HEADSHOT = 'https://a.espncdn.com/i/headshots/college-football/players/full/%d.png';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * A player and the team he is currently filed under.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        $players = $this->db->prefixed('almanac_players');
        $teams = $this->db->prefixed('almanac_teams');

        return $this->db->selectOne(
            "SELECT p.*, t.`school`, t.`slug` AS `team_slug`, t.`logo`, t.`logo_dark`,"
            . " t.`color`, t.`conference`, t.`mascot`"
            . " FROM `{$players}` p"
            . " LEFT JOIN `{$teams}` t ON t.`id` = p.`team_id`"
            . " WHERE p.`slug` = ?",
            [$slug],
        );
    }

    /**
     * Career season stats, pivoted for display.
     *
     * Stored long — one row per stat type — because that is how CFBD serves it
     * and pivoting on write would mean a migration every time they add a
     * column. A career is a few hundred rows, so it is pivoted here instead.
     *
     * @return list<array{season: int, team: ?string, team_slug: ?string, categories: array<string, array<string, string>>}>
     */
    public function careerStats(int $cfbdId): array
    {
        $stats = $this->db->prefixed('almanac_player_stats');
        $teams = $this->db->prefixed('almanac_teams');

        $rows = $this->db->select(
            "SELECT s.`season`, s.`category`, s.`stat_type`, s.`stat`,"
            . " t.`school`, t.`slug` AS `team_slug`"
            . " FROM `{$stats}` s"
            . " LEFT JOIN `{$teams}` t ON t.`id` = s.`team_id`"
            . " WHERE s.`player_id` = ?"
            . " ORDER BY s.`season` DESC",
            [$cfbdId],
        );

        $bySeason = [];

        foreach ($rows as $row) {
            $season = (int) $row['season'];

            if (!isset($bySeason[$season])) {
                $bySeason[$season] = [
                    'season' => $season,
                    'team' => $row['school'] ?? null,
                    'team_slug' => $row['team_slug'] ?? null,
                    'categories' => [],
                ];
            }

            $bySeason[$season]['categories'][(string) $row['category']][(string) $row['stat_type']]
                = (string) ($row['stat'] ?? '');
        }

        return array_values($bySeason);
    }

    /**
     * Which rosters he has been on, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function appearances(int $cfbdId): array
    {
        $seasons = $this->db->prefixed('almanac_player_seasons');
        $teams = $this->db->prefixed('almanac_teams');

        return $this->db->select(
            "SELECT s.*, t.`school`, t.`slug` AS `team_slug`, t.`logo`, t.`logo_dark`"
            . " FROM `{$seasons}` s"
            . " LEFT JOIN `{$teams}` t ON t.`id` = s.`team_id`"
            . " WHERE s.`player_id` = ?"
            . " ORDER BY s.`season` DESC",
            [$cfbdId],
        );
    }

    /**
     * Game by game for one season, pivoted the same way as the career table.
     *
     * @return list<array<string, mixed>>
     */
    public function gameLog(int $cfbdId, int $season): array
    {
        $games = $this->db->prefixed('almanac_game_stats');
        $teams = $this->db->prefixed('almanac_teams');

        $rows = $this->db->select(
            "SELECT g.`game_id`, g.`week`, g.`season_type`, g.`home_away`,"
            . " g.`category`, g.`stat_type`, g.`stat`,"
            . " o.`school` AS `opponent`, o.`slug` AS `opponent_slug`,"
            . " o.`logo` AS `opponent_logo`, o.`logo_dark` AS `opponent_logo_dark`"
            . " FROM `{$games}` g"
            . " LEFT JOIN `{$teams}` o ON o.`id` = g.`opponent_id`"
            . " WHERE g.`player_id` = ? AND g.`season` = ?"
            . " ORDER BY g.`week` ASC",
            [$cfbdId, $season],
        );

        $byGame = [];

        foreach ($rows as $row) {
            $gameId = (int) $row['game_id'];

            if (!isset($byGame[$gameId])) {
                $byGame[$gameId] = [
                    'game_id' => $gameId,
                    'week' => $row['week'],
                    'season_type' => $row['season_type'],
                    'home_away' => $row['home_away'],
                    'opponent' => $row['opponent'] ?? null,
                    'opponent_slug' => $row['opponent_slug'] ?? null,
                    'opponent_logo' => $row['opponent_logo'] ?? null,
                    'opponent_logo_dark' => $row['opponent_logo_dark'] ?? null,
                    'categories' => [],
                ];
            }

            $byGame[$gameId]['categories'][(string) $row['category']][(string) $row['stat_type']]
                = (string) ($row['stat'] ?? '');
        }

        return array_values($byGame);
    }

    /**
     * The high-school class he came out of.
     *
     * 🚨 Matched on the stored `recruit_id` only, never on name. Two players
     * called J. Williams in one class is not rare, and a wrong match puts
     * another man's high school and star rating on this page — worse than
     * showing nothing.
     *
     * @return array<string, mixed>|null
     */
    public function recruit(int $recruitId): ?array
    {
        if ($recruitId < 1) {
            return null;
        }

        $recruits = $this->db->prefixed('almanac_recruits');
        $teams = $this->db->prefixed('almanac_teams');

        return $this->db->selectOne(
            "SELECT r.*, t.`school` AS `committed_school`, t.`slug` AS `committed_slug`,"
            . " t.`logo` AS `committed_logo`, t.`logo_dark` AS `committed_logo_dark`"
            . " FROM `{$recruits}` r"
            . " LEFT JOIN `{$teams}` t ON t.`id` = r.`committed_team_id`"
            . " WHERE r.`cfbd_id` = ?",
            [$recruitId],
        );
    }

    /**
     * Portal moves.
     *
     * 🚨 Matched on the linked `player_id` where the sync managed to resolve
     * one, and otherwise on the exact name WITHIN this player's own seasons —
     * never on name alone across the whole table. The portal feed carries no
     * stable athlete id, so some players simply show no moves. An empty panel
     * beats somebody else's transfer.
     *
     * @return list<array<string, mixed>>
     */
    public function transfers(int $cfbdId, string $name): array
    {
        $transfers = $this->db->prefixed('almanac_transfers');
        $seasons = $this->db->prefixed('almanac_player_seasons');
        $teams = $this->db->prefixed('almanac_teams');

        return $this->db->select(
            "SELECT DISTINCT tr.*,"
            . " o.`slug` AS `origin_slug`, d.`slug` AS `destination_slug`"
            . " FROM `{$transfers}` tr"
            . " LEFT JOIN `{$teams}` o ON o.`id` = tr.`origin_team_id`"
            . " LEFT JOIN `{$teams}` d ON d.`id` = tr.`destination_team_id`"
            . " WHERE tr.`player_id` = ?"
            . " OR (tr.`name` = ? AND tr.`origin_team_id` IN ("
            . "   SELECT `team_id` FROM `{$seasons}` WHERE `player_id` = ?"
            . " ))"
            . " ORDER BY tr.`season` DESC",
            [$cfbdId, $name, $cfbdId],
        );
    }

    /**
     * Where a headshot WOULD be, or null if there cannot be one.
     *
     * 🚨 A URL from here is not a promise that an image exists. Negative ids are
     * CFBD's own invention for players it could not match to an ESPN athlete
     * and cannot have a photo at all — those return null. But a positive id
     * only means the man was matched to an ESPN athlete, and ESPN has no
     * picture for a great many of them: most of Vanderbilt's roster, and
     * freshmen everywhere.
     *
     * Whether the image resolves is therefore a question only the browser can
     * answer, and it is answered there — the template stacks the photo over the
     * initials and lets a 404 remove it. Checking here would be a request per
     * player against 41,588 of them.
     */
    public function headshot(int $cfbdId): ?string
    {
        return $cfbdId > 0 ? sprintf(self::HEADSHOT, $cfbdId) : null;
    }

    /**
     * The best picture Almanac has of a player: his school's, then ESPN's.
     *
     * 🚨 The school's photograph wins whenever there is one, and it is worth
     * being clear why, because ESPN's is the one that costs nothing to keep
     * current. ESPN has no picture at all for a great many real athletes and
     * almost never for a freshman — the player this extension exists to make
     * worth looking up. The school photographed him in June.
     *
     * Still nullable, and the template still stacks it over the initials with
     * `onerror`: a stored URL is a URL that resolved when the roster was read,
     * not a promise about the moment somebody opens the page.
     *
     * @param array<string, mixed> $player
     */
    public function portrait(array $player): ?string
    {
        $own = trim((string) ($player['photo_url'] ?? ''));

        return $own !== '' ? $own : $this->headshot((int) ($player['cfbd_id'] ?? 0));
    }

    /** Feet and inches from the stored total. */
    public function height(?int $inches): ?string
    {
        return $inches === null || $inches < 1
            ? null
            : sprintf('%d-%d', intdiv($inches, 12), $inches % 12);
    }

    /** CFBD's class numbering, as a word. */
    public function classYear(?int $year): ?string
    {
        return [
            1 => 'almanac.class.freshman',
            2 => 'almanac.class.sophomore',
            3 => 'almanac.class.junior',
            4 => 'almanac.class.senior',
            5 => 'almanac.class.super_senior',
        ][$year] ?? null;
    }
}
