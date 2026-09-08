<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

/**
 * The one place Roster talks to anything off this machine.
 *
 * Follows Picks' class of the same name — hard connect and read timeouts with
 * no way to opt out, HTTPS pinned, redirects not followed because a request
 * carrying an API key must never be handed to whatever a parking page points
 * at, and failure returned as a VALUE rather than thrown. "Nobody answered"
 * and "the provider says there is nothing" are different facts, and a sync that
 * confuses them wipes a season.
 *
 * 🚨 **It differs from Picks' in one way that matters: it returns the response
 * HEADERS.** CollegeFootballData reports what is left of the monthly allowance
 * in `x-calllimit-remaining`, and that number is the only thing standing
 * between a backfill and a forum whose pick'em stops updating in October. A
 * client that discards headers cannot implement the guard, so this one keeps
 * them.
 *
 * 🚨 Only ever called from the queue. Nothing here is reachable from a
 * controller — the test suite checks that by reading the source.
 *
 * Not `final`, so tests can stand a fake in front of it and drive every failure
 * path with no provider anywhere. That is the only reason.
 */
class Http
{
    /** A provider that has gone away resolves and then hangs. Fail fast. */
    private const CONNECT_TIMEOUT = 4;

    /**
     * Generous, and deliberately so. A whole season of rosters is 8.7MB and a
     * category of season stats a couple of megabytes; the same four-second
     * ceiling Picks puts on a scoreboard poll would fail every large fetch
     * here. Still bounded, and the sync is capped so a run cannot outlast the
     * gap between two scheduled ticks.
     */
    private const READ_TIMEOUT = 45;

    /**
     * The most a public page may hand back. Roster pages run to four
     * megabytes on the sites that render them server-side, so this is
     * generous; it is here to bound the worst case, not the normal one.
     */
    private const MAX_PAGE = 8 * 1024 * 1024;

    /**
     * A GET, as a decoded JSON body plus the headers.
     *
     * @param array<string, string|int> $query
     * @param array<string, string> $headers
     * @return array{0: int, 1: array<mixed>, 2: array<string, string>}
     *         status, decoded body, lowercased response headers.
     *         🚨 Status 0 means nobody answered — leave every row alone.
     */
    public function getJson(string $url, array $query = [], array $headers = []): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        if (!$this->usable() || !preg_match('#^https://#i', $url)) {
            return [0, [], []];
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return [0, [], []];
        }

        $lines = ['Accept: application/json'];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $received = [];

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::READ_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $lines,

            /*
             * 🚨 Say who is calling, because ESPN refuses anybody who does not.
             *
             * PHP's cURL extension sends NO User-Agent unless it is told to —
             * unlike the curl command, which always sends its own. ESPN's edge
             * answers 403 to a request with no User-Agent, and every roster
             * fetch would be refused with an empty page as the only symptom.
             * CollegeFootballData does not care, which is why this was never
             * needed until rosters came from somewhere else.
             *
             * 🚨 This is the true identity of the client, not a disguise. The
             * request IS libcurl, and this is the string curl itself would send
             * — measured against ESPN: `curl/8.5.0` is answered, while a
             * browser string, an invented product name and no header at all are
             * all 403. Pretending to be Chrome would be both a lie and a 403.
             *
             * The same note stands in Picks' own `Http`, where it was found.
             */
            CURLOPT_USERAGENT => 'curl/' . (curl_version()['version'] ?? '8'),

            /*
             * Collected through the callback rather than by slicing the body at
             * CURLINFO_HEADER_SIZE. With no redirects to worry about the two
             * agree, but the slice silently returns the WRONG half the moment
             * anybody sets FOLLOWLOCATION, and that is a bug that would show up
             * as an empty budget reading rather than as an error.
             */
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$received): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // No curl_close(): a no-op since PHP 8.0 and deprecated in 8.5, where
        // the deprecation notice is what somebody gets served.

        if ($raw === false) {
            return [0, [], $received];
        }

        $decoded = json_decode((string) $raw, true);

        return [$status, is_array($decoded) ? $decoded : [], $received];
    }

    /**
     * A GET of a PUBLIC page, as a raw string.
     *
     * For the athletics sites, which are a different kind of request from
     * CollegeFootballData's and are treated differently in two ways:
     *
     * 🚨 **Redirects are followed here, and they must be.** Half the athletics
     * departments publish under one domain and serve from another —
     * hurricanesports.com answers as miamihurricanes.com — so refusing to
     * follow would read as "Miami has no roster". That is only safe because
     * NOTHING IS SENT: no key, no cookie, no custom header. The rule on
     * `getJson()` exists because that request carries a credential, and a
     * credential must never be handed to whatever a parking page points at.
     *
     * The hop count is small on purpose: a redirect loop is a site that is
     * broken today, not something to spend a worker on.
     *
     * @return array{0: int, 1: string} status, body. 0 means nobody answered.
     */
    public function getPage(string $url): array
    {
        if (!$this->usable() || !preg_match('#^https://#i', $url)) {
            return [0, ''];
        }

        $handle = curl_init($url);

        if ($handle === false) {
            return [0, ''];
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::READ_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,

            /*
             * A roster page is a megabyte and some are four. Bounded so a
             * misconfigured site streaming forever cannot hold a worker until
             * the read timeout, and so nothing can hand this process more than
             * it agreed to read.
             */
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($handle, $expected, $downloaded): int
                => $downloaded > self::MAX_PAGE ? 1 : 0,

            /*
             * Several departments answer a bare client with a challenge page,
             * so the request identifies itself as what it is. Not a
             * credential — there is nothing here another visitor could not see.
             */
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ConvoroAlmanac/1.0; +https://convoro.co)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/json'],
        ]);

        /*
         * 🚨 A redirect may only ever go to another https URL, and the option
         * that says so is spelt differently depending on how old the curl this
         * site was built against is. Convoro supports PHP 8.3 and libcurl 7.85
         * is not guaranteed there, so the constant is checked rather than
         * assumed — setting an undefined one is a warning and no restriction at
         * all, which is the failure this guards against.
         */
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS_STR, 'https');
        } elseif (defined('CURLPROTO_HTTPS')) {
            curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        }

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return $raw === false ? [0, ''] : [$status, (string) $raw];
    }

    public function usable(): bool
    {
        return function_exists('curl_init');
    }
}
