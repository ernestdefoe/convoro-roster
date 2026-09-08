<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

use Convoro\Engine\Database\Connection;

/**
 * Hanging each school's own photograph on the right player.
 *
 * 🚨 **This spends no CollegeFootballData calls**, so it runs on its own
 * cadence and is capped by a count of SCHOOLS rather than by the budget guard.
 * A run reads a handful of rosters, matches them against players already
 * stored, and writes a URL — the images themselves are never fetched or
 * copied, only linked, so a run costs a few hundred kilobytes whatever the
 * roster looks like.
 *
 * 🚨 **A face is never guessed.** Two players on one roster can share a
 * surname and an initial — brothers, and it is not rare — and the wrong face on
 * a player page is worse than no face, because nothing about it looks like an
 * error. Where a name matches more than one man the jersey has to settle it,
 * and where it cannot, both are left alone.
 */
/**
 * 🚨 Not `final`, for one reason: the suite runs against the SITE'S OWN
 * database — on fbsfb that is production — so a test that drove the real class
 * through `Sync` would read a live roster and stamp live rows. A double stands
 * in front of it instead. The matching itself is tested for real, through
 * `pair()`, which takes both sides as arrays and touches nothing.
 */
class Photos
{
    /** Written to `photo_source`, so another source can be added later. */
    public const SOURCE = 'school';

    /**
     * Where the shipped catalogue lives.
     *
     * 🚨 Data, not code, on purpose. It is 130-odd hand-verified domains that
     * change when a department rebrands, and keeping it as a file means the
     * next correction is a one-line diff rather than a migration.
     */
    private const CATALOGUE = __DIR__ . '/../Data/athletics-sites.json';

    public function __construct(
        private readonly Connection $db,
        private readonly Athletics $athletics,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Fill in the athletics sites Roster ships with.
     *
     * 🚨 **Only rows that are empty or that the catalogue itself wrote.** A
     * domain typed into the admin screen is marked `manual`, and this must
     * never overwrite one: the catalogue is a best effort at a hundred and
     * thirty guessable domains, and the operator is the one who KNOWS. Anybody
     * whose correction was silently reverted by an update would, quite
     * reasonably, never trust the field again.
     *
     * Keyed on `cfbd_id`: school names change spelling between seasons
     * ("Hawai'i", "Miami (OH)") and slugs are derived from them.
     */
    public function seedCatalogue(): int
    {
        $rows = $this->catalogue();

        if ($rows === []) {
            return 0;
        }

        $teams = $this->db->prefixed('almanac_teams');
        $filled = 0;

        foreach ($rows as $row) {
            $cfbdId = (int) ($row['cfbd_id'] ?? 0);
            $domain = trim((string) ($row['domain'] ?? ''));
            $platform = (string) ($row['platform'] ?? '');

            if ($cfbdId < 1 || $domain === '' || !in_array($platform, Athletics::PLATFORMS, true)) {
                continue;
            }

            $filled += $this->db->update(
                "UPDATE `{$teams}`"
                . " SET `site_domain` = ?, `site_platform` = ?, `site_sport_id` = ?, `site_source` = 'catalogue'"
                . " WHERE `cfbd_id` = ?"
                . " AND (`site_source` = 'catalogue' OR `site_domain` = '')"
                /* Nothing to do when the row already says exactly this. */
                . " AND NOT (`site_domain` = ? AND `site_platform` = ? AND `site_sport_id` = ?)",
                [
                    $domain, $platform, (int) ($row['sport_id'] ?? 0), $cfbdId,
                    $domain, $platform, (int) ($row['sport_id'] ?? 0),
                ],
            );
        }

        return $filled;
    }

    /**
     * Read the next few schools' rosters.
     *
     * Schools come round in order of when they were last read, so a site that
     * has never been read goes first and the rest cycle. 🚨 That ordering is
     * also the retry: a school whose site was down is simply the oldest next
     * time, with no failure counter to get stuck at.
     *
     * @return array<string, int|string> what happened, for the admin screen
     */
    public function run(?int $now = null): array
    {
        $now = $now ?? time();

        if (!$this->settings->photosEnabled()) {
            return ['status' => 'off'];
        }

        $teams = $this->due($this->settings->photoTeams(), $now);
        $schools = 0;
        $written = 0;

        foreach ($teams as $team) {
            [$players, $error] = $this->athletics->roster(
                (string) $team['site_domain'],
                (string) $team['site_platform'],
                (int) $team['site_sport_id'],
            );

            $found = count($players);
            $matched = $error === '' ? $this->apply((int) $team['id'], $players) : 0;

            $this->stamp((int) $team['id'], $now, $found, $error);

            $schools++;
            $written += $matched;
        }

        return ['status' => 'ran', 'schools' => $schools, 'photos' => $written];
    }

    /**
     * Match a school's roster to the players stored for it, and write the URLs.
     *
     * @param list<array<string, mixed>> $players
     */
    public function apply(int $teamId, array $players): int
    {
        if ($players === []) {
            return 0;
        }

        return $this->write($this->pair($players, $this->squadRows($teamId)));
    }

    /**
     * Which stored player each photograph belongs to.
     *
     * Kept apart from the database on purpose: this is the part that can be
     * wrong in a way nobody would notice, so it is the part that is tested
     * with both sides handed in as arrays.
     *
     * @param list<array<string, mixed>> $players from the school's site
     * @param list<array<string, mixed>> $squad from `almanac_players`
     * @return array<int, string> player id => photo URL
     */
    public function pair(array $players, array $squad): array
    {
        $ours = $this->byName($squad);

        if ($ours === []) {
            return [];
        }

        /*
         * 🚨 The same rule on the site's side of the comparison, and it is not
         * theoretical: two of the four readers pick up COACHES as well as
         * players, because on those sites a coach's card is built from the same
         * markup. A coach who shares a surname and an initial with one of his
         * players — which is exactly what a father coaching his son looks
         * like — would otherwise be written over the player's own face,
         * depending on nothing but which of them the page listed last.
         */
        $seen = [];

        foreach ($players as $player) {
            $key = $this->key((string) $player['first'], (string) $player['last']);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }

        $updates = [];

        foreach ($players as $player) {
            $key = $this->key((string) $player['first'], (string) $player['last']);

            if ($key === '' || !isset($ours[$key]) || (string) $player['photo'] === '') {
                continue;
            }

            if (($seen[$key] ?? 0) > 1) {
                continue;
            }

            $candidates = $ours[$key];

            /*
             * 🚨 One name, two men. The jersey is the only thing that separates
             * them, and where it does not — the school lists no number, or both
             * brothers wear one Roster has not got — NOBODY gets the photo.
             * A page with no picture is honest; a page with his brother's is a
             * mistake nothing on the screen would reveal.
             */
            if (count($candidates) > 1) {
                $jersey = $player['jersey'];
                $candidates = array_values(array_filter(
                    $candidates,
                    static fn (array $row): bool => $jersey !== null
                        && $row['jersey'] !== null
                        && (int) $row['jersey'] === (int) $jersey,
                ));

                if (count($candidates) !== 1) {
                    continue;
                }
            }

            $updates[(int) $candidates[0]['id']] = (string) $player['photo'];
        }

        return $updates;
    }

    /**
     * How much of the site has a school photograph, for the admin screen.
     *
     * Counted over the players on a CURRENT roster rather than all 41,588,
     * because a photograph is only ever published for a current player — a
     * denominator that included every senior since 2021 would report about
     * fifteen per cent forever and mean nothing.
     *
     * @return array{covered: int, current: int, sites: int, missing: int}
     */
    public function coverage(): array
    {
        $players = $this->db->prefixed('almanac_players');
        $teams = $this->db->prefixed('almanac_teams');

        $counts = $this->db->selectOne(
            "SELECT COUNT(*) AS `current`,"
            . " SUM(CASE WHEN `photo_url` IS NOT NULL AND `photo_url` <> '' THEN 1 ELSE 0 END) AS `covered`"
            . " FROM `{$players}` WHERE `team_id` > 0 AND `last_season` >= ?",
            [$this->settings->season()],
        ) ?? [];

        $sites = $this->db->selectOne(
            "SELECT SUM(CASE WHEN `site_domain` <> '' THEN 1 ELSE 0 END) AS `have`,"
            . " SUM(CASE WHEN `site_domain` = '' THEN 1 ELSE 0 END) AS `missing`"
            . " FROM `{$teams}`"
        ) ?? [];

        return [
            'covered' => (int) ($counts['covered'] ?? 0),
            'current' => (int) ($counts['current'] ?? 0),
            'sites' => (int) ($sites['have'] ?? 0),
            'missing' => (int) ($sites['missing'] ?? 0),
        ];
    }

    /**
     * Every school, with its site and the last read, for the admin screen.
     *
     * @return list<array<string, mixed>>
     */
    public function sites(): array
    {
        $teams = $this->db->prefixed('almanac_teams');

        return $this->db->select(
            "SELECT `id`, `school`, `conference`, `site_domain`, `site_platform`,"
            . " `site_sport_id`, `site_source`, `photos_at`, `photos_found`, `photos_error`"
            . " FROM `{$teams}` ORDER BY `site_domain` = '' DESC, `school`"
        );
    }

    /**
     * Point a school at a site by hand, or clear it.
     *
     * 🚨 Marked `manual`, which is what stops the shipped catalogue putting its
     * guess back on the next update. Clearing the domain clears the mark too,
     * so a school can be handed back to the catalogue.
     */
    public function setSite(int $teamId, string $domain, string $platform, int $sportId): bool
    {
        if (!in_array($platform, Athletics::PLATFORMS, true)) {
            $platform = '';
        }

        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = (string) preg_replace('#[/?].*$#', '', $domain);

        if ($domain !== '' && ($platform === '' || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) !== 1)) {
            return false;
        }

        $this->db->table('almanac_teams')->where('id', $teamId)->updateAll([
            'site_domain' => $domain,
            'site_platform' => $domain === '' ? '' : $platform,
            'site_sport_id' => $domain === '' ? 0 : max(0, $sportId),
            'site_source' => $domain === '' ? '' : 'manual',
            /* Read it on the next tick rather than a fortnight from now. */
            'photos_at' => 0,
            'photos_error' => '',
        ]);

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * The schools whose turn it is.
     *
     * @return list<array<string, mixed>>
     */
    private function due(int $limit, int $now): array
    {
        $teams = $this->db->prefixed('almanac_teams');
        $stale = $now - ($this->settings->photoDays() * 86400);

        return $this->db->select(
            "SELECT `id`, `site_domain`, `site_platform`, `site_sport_id`"
            . " FROM `{$teams}`"
            . " WHERE `site_domain` <> '' AND `photos_at` < ?"
            . " ORDER BY `photos_at`, `id`"
            . ' LIMIT ' . max(1, $limit),
            [$stale],
        );
    }

    /**
     * The players Roster believes are at a school now.
     *
     * @return list<array<string, mixed>>
     */
    private function squadRows(int $teamId): array
    {
        $players = $this->db->prefixed('almanac_players');

        return $this->db->select(
            "SELECT `id`, `first_name`, `last_name`, `name`, `jersey`"
            . " FROM `{$players}` WHERE `team_id` = ?",
            [$teamId],
        );
    }

    /**
     * Those players indexed by match key.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array<string, mixed>>>
     */
    private function byName(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $first = (string) ($row['first_name'] ?? '');
            $last = (string) ($row['last_name'] ?? '');

            /*
             * Older roster years arrive as a single name field with no split,
             * so fall back to the whole name rather than skipping the player.
             */
            if ($last === '') {
                $whole = trim((string) ($row['name'] ?? ''));
                $at = strrpos($whole, ' ');

                if ($at === false) {
                    continue;
                }

                $first = substr($whole, 0, $at);
                $last = substr($whole, $at + 1);
            }

            $key = $this->key($first, $last);

            if ($key === '') {
                continue;
            }

            $out[$key][] = $row;
        }

        return $out;
    }

    /**
     * First initial plus surname, stripped of everything two sources disagree
     * about.
     *
     * 🚨 The initial, not the given name. Rosters disagree on given names
     * constantly — A.K. and AK, Mike and Michael, Bo in quotation marks — and
     * on a hundred-man roster a surname alone collides several times.
     */
    private function key(string $first, string $last): string
    {
        $last = $this->plain($last);

        /* A suffix belongs to the father as often as the son. */
        $last = trim((string) preg_replace('/\b(?:jr|sr|ii|iii|iv|v)\b/', '', $last));

        if ($last === '') {
            return '';
        }

        /*
         * 🚨 The LAST word of the surname, not the whole thing joined up.
         * Schools print nicknames inside the name — Kentucky's roster carries
         * `Elijah "Bo" Barnes` — and CFBD does not, so anything that kept every
         * word would compare `bobarnes` against `barnes` and quietly match
         * nobody. Two men whose surnames end the same way collide instead, and
         * a collision is resolved by the jersey or left alone.
         */
        $words = explode(' ', $last);
        $last = (string) end($words);

        $first = $this->plain($first);

        return ($first === '' ? '' : $first[0]) . ':' . $last;
    }

    /** Lowercase, unaccented, letters and spaces. */
    private function plain(string $value): string
    {
        $value = (string) preg_replace('/&[a-z]+;|&#\d+;/i', ' ', $value);

        /*
         * iconv needs a locale to transliterate and returns false without one,
         * so the accented letters are mapped directly. Nothing here has to be
         * pretty; it only has to be the SAME on both sides of the comparison.
         */
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'š' => 's', 'ž' => 'z',
        ]);

        $value = mb_strtolower($value, 'UTF-8');

        /*
         * 🚨 An apostrophe CLOSES UP and everything else opens out. O'Kafor is
         * one word and Okafor on the other side of the comparison; turning the
         * apostrophe into a space would leave `kafor` matching nothing.
         * A hyphen is the opposite: Smith-Reed is two words on both sides.
         */
        $value = str_replace(["'", '’', '`'], '', $value);
        $value = (string) preg_replace('/[^a-z ]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /**
     * @param array<int, string> $updates player id => photo URL
     */
    private function write(array $updates): int
    {
        if ($updates === []) {
            return 0;
        }

        $players = $this->db->prefixed('almanac_players');
        $written = 0;

        foreach ($updates as $id => $url) {
            /*
             * 🚨 Only when it changed. Without the guard every run rewrites
             * every player on every roster it reads, which is fifteen thousand
             * pointless writes a cycle and an `updated_at` that says the whole
             * site changed last night.
             */
            $written += $this->db->update(
                "UPDATE `{$players}` SET `photo_url` = ?, `photo_source` = ?"
                . ' WHERE `id` = ? AND NOT (`photo_url` <=> ?)',
                [$url, self::SOURCE, $id, $url],
            );
        }

        return $written;
    }

    private function stamp(int $teamId, int $now, int $found, string $error): void
    {
        $columns = ['photos_at' => $now, 'photos_error' => mb_substr($error, 0, 40)];

        /*
         * 🚨 The count is only overwritten by a run that worked. A site that
         * was down for an afternoon otherwise reports "0 photographs" beside a
         * roster full of them, which reads as a school that stopped publishing.
         */
        if ($error === '') {
            $columns['photos_found'] = $found;
        }

        $this->db->table('almanac_teams')->where('id', $teamId)->updateAll($columns);
    }

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        if (!is_readable(self::CATALOGUE)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents(self::CATALOGUE), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }
}
