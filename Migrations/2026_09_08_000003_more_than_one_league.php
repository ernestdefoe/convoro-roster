<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;

/**
 * Rosters for more than college football.
 *
 * 🚨 Three changes, and the awkward one is `cfbd_id`.
 *
 * It is NOT NULL and unique on both teams and players, which was exactly right
 * when CollegeFootballData was the only place a row could come from. An NFL
 * team has no CFBD id and never will, and storing 0 for all of them would
 * collide on the second row. So it becomes nullable — MySQL treats NULLs in a
 * unique index as distinct, which is precisely the behaviour wanted here — and
 * a provider-neutral `external_id` carries ESPN's, unique per league.
 *
 * 🚨 `position_group` exists because the offence/defence/specialists split is a
 * fact about gridiron and nothing else. ESPN's roster endpoint already groups
 * its own way — Pitchers and Catchers, Centers and Wingers — so the group is
 * STORED where a provider gave one, and the gridiron map is used only where
 * nobody did. Mapping a shortstop through a list of football positions returns
 * "other" for every player on the team.
 */
return new class () extends Migration {
    public function up(): void
    {
        $teams = $this->db->prefixed('almanac_teams');
        $players = $this->db->prefixed('almanac_players');

        foreach ([$teams, $players] as $table) {
            if (!$this->hasColumn($table, 'league')) {
                $this->db->query(
                    "ALTER TABLE `{$table}` ADD COLUMN `league` VARCHAR(20) NOT NULL DEFAULT 'cfb'"
                );
            }

            if (!$this->hasColumn($table, 'external_id')) {
                $this->db->query(
                    "ALTER TABLE `{$table}` ADD COLUMN `external_id` VARCHAR(40) NULL DEFAULT NULL"
                );
            }

            /*
             * 🚨 NULLs are distinct in a MySQL unique index, so every existing
             * college row — all of them with a NULL `external_id` — coexists
             * happily under this, and only rows that actually carry a
             * provider id are constrained.
             */
            if (!$this->hasIndex($table, 'almanac_external')) {
                $this->db->query(
                    "ALTER TABLE `{$table}` ADD UNIQUE `almanac_external` (`league`, `external_id`)"
                );
            }
        }

        // 🚨 The signedness and width are preserved exactly; only NULL changes.
        $this->db->query("ALTER TABLE `{$teams}` MODIFY `cfbd_id` INT UNSIGNED NULL DEFAULT NULL");
        $this->db->query("ALTER TABLE `{$players}` MODIFY `cfbd_id` BIGINT NULL DEFAULT NULL");

        /*
         * 🚨 When each club's roster was last fetched. ESPN answers one club
         * per call, so the sync works through them oldest first — and without
         * a stamp it would re-fetch the same first dozen every run and never
         * reach the rest.
         */
        if (!$this->hasColumn($teams, 'roster_at')) {
            $this->db->query(
                "ALTER TABLE `{$teams}` ADD COLUMN `roster_at` DATETIME NULL DEFAULT NULL"
            );
        }

        if (!$this->hasColumn($players, 'position_group')) {
            $this->db->query(
                "ALTER TABLE `{$players}` ADD COLUMN `position_group` VARCHAR(40) NOT NULL DEFAULT ''"
            );
        }
    }

    public function down(): void
    {
        foreach ([
            $this->db->prefixed('almanac_teams'),
            $this->db->prefixed('almanac_players'),
        ] as $table) {
            if ($this->hasIndex($table, 'almanac_external')) {
                $this->db->query("ALTER TABLE `{$table}` DROP INDEX `almanac_external`");
            }

            foreach (['league', 'external_id', 'position_group', 'roster_at'] as $column) {
                if ($this->hasColumn($table, $column)) {
                    $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
                }
            }
        }

        /*
         * 🚨 `cfbd_id` is left nullable. Putting NOT NULL back would fail on
         * every row this migration made possible, and a rollback that cannot
         * complete is worse than a column that permits a null nobody writes.
         */
    }

    private function hasColumn(string $table, string $column): bool
    {
        foreach ($this->db->select("SHOW COLUMNS FROM `{$table}`") as $row) {
            if (($row['Field'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private function hasIndex(string $table, string $index): bool
    {
        foreach ($this->db->select("SHOW INDEX FROM `{$table}`") as $row) {
            if (($row['Key_name'] ?? '') === $index) {
                return true;
            }
        }

        return false;
    }
};
