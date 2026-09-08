<?php

declare(strict_types=1);

namespace Convoro\Extensions\Almanac\Services\Leagues;

/**
 * One competition this extension can hold rosters for.
 */
final class League
{
    public function __construct(
        /** The stored key. Goes in `almanac_teams.league` and never changes. */
        public readonly string $key,
        /** What a reader sees. */
        public readonly string $name,
        /** Which source answers for it: `cfbd` or `espn`. */
        public readonly string $provider,
        /** ESPN's own path — `football/nfl`. Empty where no ESPN endpoint covers it. */
        public readonly string $espnPath = '',
        /**
         * 🚨 Whether this league has the college-football furniture: a
         * recruiting class, a transfer portal, season-by-season statistics for
         * every player.
         *
         * None of it exists in professional sport — there is no signing class
         * for the NFL in the sense this extension means, and ESPN's roster
         * endpoint answers none of it. Rendering those panels empty under every
         * professional team would read as a broken page rather than as a sport
         * that does not have the thing.
         */
        public readonly bool $collegiate = false,
    ) {
    }
}
