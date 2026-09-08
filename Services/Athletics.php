<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

/**
 * Reading a school's own roster, for the photograph on it.
 *
 * 🚨 **Nothing here touches CollegeFootballData or its allowance.** These are
 * the athletics departments' own sites, a separate provider with no shared
 * budget, which is why photos can carry on refreshing while the CFBD mirror
 * sits idle for a week at a time.
 *
 * 🚨 **Four platforms, because the departments are mid-migration between
 * them.** They were established by asking every FBS site in turn what answered:
 *
 *   sidearm  `/api/v2/sports` then `/api/v2/Rosters?sportId=N` — JSON, ~60 sites
 *   wmt      `/website-api/...` — JSON, the newer build, ~30 sites
 *   classic  the old server-rendered SIDEARM template — HTML, ~20 sites
 *   wpx      a WordPress build — HTML, a handful, including Kentucky and Miami
 *
 * The platform is stored per team rather than sniffed, so a normal run is one
 * request for a JSON site and one for an HTML one.
 *
 * 🚨 **A parser is a promise that expires.** The two HTML readers depend on
 * markup nobody owes Roster, and August is when athletics sites get rebuilt.
 * Both are written to return NOTHING rather than nonsense when the shape
 * changes — no partial rows, no names scraped out of navigation — and a team
 * that returns nothing keeps the photos it already had. What breaks is
 * therefore staleness, which the admin screen can show, rather than a roster of
 * wrong faces, which nobody would notice.
 *
 * Not `final`: the test suite stands a fake in front of it. That is the only
 * reason.
 */
class Athletics
{
    /** Every platform this can read, in the order discovery tries them. */
    public const PLATFORMS = ['sidearm', 'wmt', 'classic', 'wpx'];

    /**
     * SIDEARM's platform-wide id for football. Sites number their own sports
     * freely; this constant is the same everywhere and is what identifies
     * football when a site's own ids are unknown.
     */
    private const GLOBAL_FOOTBALL = 5;

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * A school's current football roster, as far as its site will say.
     *
     * @return array{0: list<array{first: string, last: string, jersey: ?int, photo: string}>, 1: string}
     *         the players, and a fault. 🚨 An empty list with an empty fault is
     *         a real answer — a site can have a roster page and no photographs
     *         on it — and it must not be mistaken for a failure and retried.
     */
    public function roster(string $domain, string $platform, int $sportId = 0): array
    {
        $domain = $this->host($domain);

        if ($domain === '') {
            return [[], 'no_domain'];
        }

        return match ($platform) {
            'sidearm' => $this->sidearm($domain, $sportId),
            'wmt' => $this->wmt($domain, $sportId),
            'classic' => $this->classic($domain),
            'wpx' => $this->wpx($domain),
            default => [[], 'unknown_platform'],
        };
    }

    /* ------------------------------------------------------------------ */
    /* sidearm — /api/v2                                                   */
    /* ------------------------------------------------------------------ */

    /** @return array{0: list<array<string, mixed>>, 1: string} */
    private function sidearm(string $domain, int $sportId): array
    {
        if ($sportId < 1) {
            [$sports, $error] = $this->json("https://{$domain}/api/v2/sports");

            if ($error !== '') {
                return [[], $error];
            }

            foreach ($sports as $sport) {
                if (!is_array($sport) || ($sport['nonSport'] ?? false)) {
                    continue;
                }

                $title = strtolower((string) ($sport['title'] ?? ''));

                if (($sport['globalSportId'] ?? null) === self::GLOBAL_FOOTBALL || $title === 'football') {
                    $sportId = (int) ($sport['id'] ?? 0);
                    break;
                }
            }

            if ($sportId < 1) {
                return [[], 'no_football'];
            }
        }

        [$body, $error] = $this->json("https://{$domain}/api/v2/Rosters?sportId={$sportId}");

        if ($error !== '') {
            return [[], $error];
        }

        $items = $body['items'] ?? [];

        if (!is_array($items) || !isset($items[0]['players']) || !is_array($items[0]['players'])) {
            return [[], 'no_roster'];
        }

        $out = [];

        foreach ($items[0]['players'] as $player) {
            if (!is_array($player)) {
                continue;
            }

            /*
             * 🚨 `image` is an object, and `absoluteUrl` is the only field on
             * it that is usable as-is: `url` is site-relative and `images` is
             * null on most sites.
             */
            $image = is_array($player['image'] ?? null) ? (string) ($player['image']['absoluteUrl'] ?? '') : '';

            $out[] = $this->player(
                (string) ($player['firstName'] ?? ''),
                (string) ($player['lastName'] ?? ''),
                $player['jerseyNumber'] ?? null,
                $image,
                $domain,
            );
        }

        return [$this->clean($out), ''];
    }

    /* ------------------------------------------------------------------ */
    /* wmt — /website-api                                                  */
    /* ------------------------------------------------------------------ */

    /** @return array{0: list<array<string, mixed>>, 1: string} */
    private function wmt(string $domain, int $sportId): array
    {
        /*
         * 🚨 `per_page`, or football is invisible. Every list on this API pages
         * at fifteen and departments run twenty-odd sports, so the default page
         * carries whichever ones sort first — which is how a site with a
         * perfectly good football roster reads as not having football at all.
         */
        [$sports, $error] = $this->json("https://{$domain}/website-api/sports?per_page=200");

        if ($error !== '') {
            return [[], $error];
        }

        $football = null;

        foreach ($sports['data'] ?? [] as $sport) {
            if (!is_array($sport)) {
                continue;
            }

            if (strtolower((string) ($sport['slug'] ?? '')) === 'football'
                || strtolower((string) ($sport['name'] ?? '')) === 'football') {
                $football = $sport;
                break;
            }
        }

        if ($football === null) {
            return [[], 'no_football'];
        }

        /*
         * 🚨 The sport says which roster is current, and nothing else does.
         * Sorting rosters by id picks a signing class, which is also a roster
         * and is created later than the season's; matching the name fails
         * because the sites disagree on what to call one — "Football 2026",
         * "2026 Football", "2026 Football Roster" — and Missouri has published
         * a hundred years of them.
         */
        $rosterId = (int) ($football['default_roster_id'] ?? 0);

        if ($rosterId < 1) {
            $rosterId = $this->wmtNewestRoster($domain, (int) ($football['id'] ?? 0));
        }

        if ($rosterId < 1) {
            return [[], 'no_roster'];
        }

        [$body, $error] = $this->json(
            "https://{$domain}/website-api/player-rosters"
            . '?filter%5Broster_id%5D=' . $rosterId
            . '&include=photo,player&per_page=200&page=1'
        );

        if ($error !== '') {
            return [[], $error];
        }

        $rows = $body['data'] ?? null;

        if (!is_array($rows)) {
            return [[], 'no_roster'];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $person = is_array($row['player'] ?? null) ? $row['player'] : [];
            $photo = is_array($row['photo'] ?? null) ? (string) ($row['photo']['url'] ?? '') : '';

            $out[] = $this->player(
                (string) ($person['first_name'] ?? ''),
                (string) ($person['last_name'] ?? ''),
                $row['jersey_number'] ?? ($person['jersey_number'] ?? null),
                $photo,
                $domain,
            );
        }

        return [$this->clean($out), ''];
    }

    /** The most recent football roster a site lists, by the year in its name. */
    private function wmtNewestRoster(string $domain, int $sportId): int
    {
        if ($sportId < 1) {
            return 0;
        }

        [$body, $error] = $this->json(
            "https://{$domain}/website-api/rosters?filter%5Bsport_id%5D={$sportId}&sort=-id&per_page=200"
        );

        if ($error !== '') {
            return 0;
        }

        $best = 0;
        $bestYear = -1;

        foreach ($body['data'] ?? [] as $roster) {
            if (!is_array($roster)) {
                continue;
            }

            preg_match_all('/\b(?:19|20)\d{2}\b/', (string) ($roster['name'] ?? ''), $years);
            $year = $years[0] === [] ? -1 : max(array_map('intval', $years[0]));

            if ($year > $bestYear) {
                $best = (int) ($roster['id'] ?? 0);
                $bestYear = $year;
            }
        }

        return $best;
    }

    /* ------------------------------------------------------------------ */
    /* classic — the old server-rendered SIDEARM template                  */
    /* ------------------------------------------------------------------ */

    /** @return array{0: list<array<string, mixed>>, 1: string} */
    private function classic(string $domain): array
    {
        [$html, $error] = $this->page("https://{$domain}/sports/football/roster");

        if ($error !== '') {
            return [[], $error];
        }

        if (!str_contains($html, 'sidearm-roster-player')) {
            return [[], 'not_classic'];
        }

        preg_match_all('#class="sidearm-roster-player"(.*?)</li>#s', $html, $blocks);

        $out = [];

        foreach ($blocks[1] as $block) {
            /*
             * The bio link's own label, rather than the heading text: it is the
             * one place the name appears without markup inside it, and it is
             * absent from the container rows, so this cannot pick up a section
             * heading and call it a player.
             */
            if (!preg_match('/aria-label="([^"]+?)\s*-\s*View Full Bio"/', $block, $name)) {
                continue;
            }

            preg_match('/data-src="([^"]+)"/', $block, $image);
            preg_match(
                '#sidearm-roster-player-jersey-number[^>]*>\s*(\d+)#',
                $block,
                $jersey,
            );

            $parts = $this->split(html_entity_decode($name[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $out[] = $this->player(
                $parts[0],
                $parts[1],
                $jersey[1] ?? null,
                /* The thumbnail's query asks for 80px; the bare path is full size. */
                strtok((string) ($image[1] ?? ''), '?') ?: '',
                $domain,
            );
        }

        return [$this->clean($out), ''];
    }

    /* ------------------------------------------------------------------ */
    /* wpx — the WordPress build                                           */
    /* ------------------------------------------------------------------ */

    /** @return array{0: list<array<string, mixed>>, 1: string} */
    private function wpx(string $domain): array
    {
        [$html, $error] = $this->page("https://{$domain}/sports/football/roster");

        if ($error !== '') {
            return [[], $error];
        }

        /*
         * 🚨 Keyed on the PHOTO, not on the card. These sites share no roster
         * markup at all — Kentucky wraps a player in `roster-item__`, Miami in
         * `player__meta` — so matching the layout would mean a parser per
         * school again. What they do share is the image pipeline: every player
         * photograph is an `imgproxy` URL on a tag whose `title` is his name.
         * Matching that is both simpler and exactly what is wanted, since a
         * card with no photograph is of no use here anyway.
         */
        if (!str_contains($html, 'imgproxy')) {
            return [[], 'not_wpx'];
        }

        preg_match_all('/<(?:img|span)[^>]*>/', $html, $tags);

        $out = [];

        foreach ($tags[0] as $tag) {
            if (!str_contains($tag, 'imgproxy')
                || !preg_match('/title="([^"]+)"/', $tag, $name)) {
                continue;
            }

            preg_match('/(?:src|data-bg)="([^"]*imgproxy[^"]*)"/', $tag, $image);

            $parts = $this->split(html_entity_decode($name[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            $out[] = $this->player($parts[0], $parts[1], null, (string) ($image[1] ?? ''), $domain);
        }

        return [$this->clean($out), ''];
    }

    /* ------------------------------------------------------------------ */
    /* Shared                                                              */
    /* ------------------------------------------------------------------ */

    /** @return array{0: array<mixed>, 1: string} */
    private function json(string $url): array
    {
        [$status, $body] = $this->http->getPage($url);

        if ($status === 0) {
            return [[], 'unreachable'];
        }

        if ($status !== 200) {
            return [[], 'http_' . $status];
        }

        $decoded = json_decode($body, true);

        /*
         * 🚨 Not an error worth a retry: several departments answer any
         * unknown path with a 200 and a marketing page, so "not JSON" is the
         * ordinary way of learning this site is not on this platform.
         */
        return is_array($decoded) ? [$decoded, ''] : [[], 'not_json'];
    }

    /** @return array{0: string, 1: string} */
    private function page(string $url): array
    {
        [$status, $body] = $this->http->getPage($url);

        if ($status === 0) {
            return ['', 'unreachable'];
        }

        return $status === 200 ? [$body, ''] : ['', 'http_' . $status];
    }

    /**
     * One player, normalised.
     *
     * @return array{first: string, last: string, jersey: ?int, photo: string}
     */
    private function player(string $first, string $last, mixed $jersey, string $photo, string $domain): array
    {
        $photo = trim($photo);

        if ($photo !== '' && str_starts_with($photo, '/')) {
            $photo = "https://{$domain}" . $photo;
        }

        /* Anything that is not an https URL is not something to store. */
        if ($photo !== '' && !preg_match('#^https://#i', $photo)) {
            $photo = '';
        }

        /*
         * A jersey is a LABEL, not a number: "0" and "00" are two players on
         * the same team, and some sites hand back "1A". It is only ever used to
         * separate two men with the same name, so what matters is that it
         * survives round-tripping, not that it is arithmetic.
         */
        $number = is_scalar($jersey) ? trim((string) $jersey) : '';

        return [
            'first' => trim($first),
            'last' => trim($last),
            'jersey' => $number === '' || !ctype_digit($number) ? null : (int) $number,
            'photo' => mb_substr($photo, 0, 500),
        ];
    }

    /**
     * A name, split into the halves the matcher works on.
     *
     * @return array{0: string, 1: string}
     */
    private function split(string $name): array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            return ['', ''];
        }

        $at = mb_strpos($name, ' ');

        return $at === false
            ? [$name, '']
            : [mb_substr($name, 0, $at), mb_substr($name, $at + 1)];
    }

    /**
     * Drop the rows that carry nothing worth storing.
     *
     * 🚨 This is what stops a rebuilt page turning into wrong data. A parser
     * facing markup it no longer understands produces rows with no surname or
     * no photograph, and every one of them is dropped here — so the failure
     * mode is an empty answer, which the caller treats as "keep what you have",
     * rather than a roster of half-read names.
     *
     * @param list<array<string, mixed>> $players
     * @return list<array<string, mixed>>
     */
    private function clean(array $players): array
    {
        $out = [];
        $seen = [];

        foreach ($players as $player) {
            if ($player['last'] === '' || $player['photo'] === '') {
                continue;
            }

            /* Coaches appear twice on some pages, players once. */
            $key = strtolower($player['first'] . '|' . $player['last'] . '|' . (string) $player['jersey']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $player;
        }

        return $out;
    }

    /** A bare host, whatever an operator typed into the admin screen. */
    private function host(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = (string) preg_replace('#[/?].*$#', '', $domain);

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1 ? $domain : '';
    }
}
