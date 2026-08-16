<?php

declare(strict_types=1);

/*
 * A one-off check that the four readers still work against the real sites.
 *
 * 🚨 Deliberately NOT part of the suite. It makes live requests to athletics
 * departments, and a test that needs the internet is a test that fails at four
 * in the morning because a school rebuilt its website. Run it by hand when a
 * reader is changed, or when the admin screen shows a platform going quiet.
 *
 *   php extensions/almanac/tests/probe-athletics.php
 *
 * One school per platform, chosen because each broke something while the
 * readers were being written: Alabama is the plain case, Auburn pages its sport
 * list, Navy renders server-side, Kentucky and Miami share an image pipeline and
 * no markup at all, and Clemson's football sport id is 20.
 */

$root = dirname(__DIR__, 3);

require $root . '/engine/Autoload.php';

Convoro\Engine\Convoro::boot(CONVORO_ROOT);

$athletics = new Convoro\Extensions\Almanac\Services\Athletics(
    new Convoro\Extensions\Almanac\Services\Http(),
);

$cases = [
    ['Alabama', 'rolltide.com', 'sidearm', 3],
    ['Auburn', 'auburntigers.com', 'wmt', 6],
    ['Navy', 'navysports.com', 'classic', 0],
    ['Kentucky', 'ukathletics.com', 'wpx', 0],
    ['Miami', 'hurricanesports.com', 'wpx', 0],
    ['Clemson', 'clemsontigers.com', 'wmt', 20],
];

foreach ($cases as [$school, $domain, $platform, $sportId]) {
    $started = microtime(true);
    [$players, $error] = $athletics->roster($domain, $platform, $sportId);

    printf(
        "%-10s %-24s %-8s %3d players %5dms  %s\n",
        $school,
        $domain,
        $platform,
        count($players),
        round((microtime(true) - $started) * 1000),
        $error !== '' ? 'ERROR ' . $error : mb_substr((string) ($players[0]['photo'] ?? ''), 0, 70),
    );
}
