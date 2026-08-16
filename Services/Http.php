<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services;

/**
 * The one place Almanac talks to anything off this machine.
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

    public function usable(): bool
    {
        return function_exists('curl_init');
    }
}
