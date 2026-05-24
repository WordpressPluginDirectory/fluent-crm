<?php

namespace FluentCrmMigrations;

class FunnelSubscribers
{
    /**
     * Migrate the table.
     *
     * @param bool $isForced
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix .'fc_funnel_subscribers';

        $indexPrefix = $wpdb->prefix .'fc_fsx_';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `funnel_id` BIGINT UNSIGNED NULL,
                `starting_sequence_id` BIGINT UNSIGNED NULL,
                `next_sequence` BIGINT UNSIGNED NULL,
                `subscriber_id` BIGINT UNSIGNED NULL,
                `last_sequence_id` BIGINT UNSIGNED NULL,
                `next_sequence_id` BIGINT UNSIGNED NULL,
                `last_sequence_status` VARCHAR(50) DEFAULT 'pending',
                `status` VARCHAR(50) DEFAULT 'active',
                `type` VARCHAR(50) DEFAULT 'funnel',
                `last_executed_time` TIMESTAMP NULL,
                `next_execution_time` TIMESTAMP NULL,
                `notes` TEXT NULL,
                `source_trigger_name` VARCHAR(192) NULL,
                `source_ref_id` BIGINT UNSIGNED NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `{$indexPrefix}_fidx` (`funnel_id` ASC),
                INDEX `{$indexPrefix}_fsq_idx` (`subscriber_id` ASC),
                KEY `status` (`status`),
                KEY `type` (`type`),
                KEY `next_execution_time` (`next_execution_time`),
                KEY `next_sequence` (`next_sequence`),
                UNIQUE KEY `funnel_subscriber_idx` (`funnel_id`, `subscriber_id`),
                KEY `status_next_exec_idx` (`status`, `next_execution_time`)
            ) $charsetCollate;";
            dbDelta($sql);
        } else {

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $indexes = $wpdb->get_results("SHOW INDEX FROM $table");
            $indexedColumns = [];
            foreach ($indexes as $index) {
                $indexedColumns[] = $index->Column_name;
            }

            if(!in_array('status', $indexedColumns)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $sql = "ALTER TABLE {$table} ADD INDEX `status` (`status`),
                        ADD INDEX `type` (`type`),
                        ADD INDEX `next_execution_time` (`next_execution_time`),
                        ADD INDEX `next_sequence` (`next_sequence`);";

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query($sql);
            }

            $indexNames = [];
            foreach ($indexes as $index) {
                $indexNames[] = $index->Key_name;
            }

            // Establish the unique constraint on (funnel_id, subscriber_id).
            // Two pre-existing states must converge to "unique key present":
            //   (a) Old non-unique index of the same name → drop + re-add as unique.
            //   (b) No index by that name at all → add the unique key.
            // Both states can have accumulated duplicate rows (the constraint
            // is what would have prevented them), so dedupe ALWAYS runs first.
            // The previous version of this migration skipped dedupe in case
            // (b) under the assumption it was a fresh install — but case (b)
            // also fires for sites upgraded from a build that predates this
            // unique-key change, which are the sites most likely to have
            // duplicates. Skipping dedupe there caused ALTER to fail silently
            // with "Duplicate entry", leaving the table without protection.
            $existingFunnelSubIdx = null;
            foreach ($indexes as $index) {
                if ($index->Key_name === 'funnel_subscriber_idx') {
                    $existingFunnelSubIdx = $index;
                    break;
                }
            }
            $alreadyUnique = $existingFunnelSubIdx && (int)$existingFunnelSubIdx->Non_unique === 0;

            if (!$alreadyUnique) {
                // Sweep garbage rows before dedupe. funnel_id and subscriber_id
                // are BIGINT UNSIGNED NULL in the schema, but a row with either
                // NULL or 0 is never a meaningful funnel enrollment — those come
                // from past bugs where the writer didn't have a valid id. They
                // would also confuse the dedupe (GROUP BY treats NULLs as one
                // group; UNIQUE KEY permits multiple NULL rows; so dedupe would
                // over-delete what the constraint would have allowed), and any
                // surviving row would silently block legitimate future writes
                // for that funnel once the unique key is in place.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("DELETE FROM {$table} WHERE funnel_id IS NULL OR funnel_id = 0 OR subscriber_id IS NULL OR subscriber_id = 0");

                // Pre-check duplicates so the heavy DELETE … JOIN is skipped
                // when the table is already clean.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $hasDuplicates = (int)$wpdb->get_var("SELECT COUNT(*) FROM (
                    SELECT funnel_id, subscriber_id
                    FROM {$table}
                    GROUP BY funnel_id, subscriber_id
                    HAVING COUNT(*) > 1
                ) dups");

                $dedupeOk = true;
                if ($hasDuplicates > 0) {
                    // Remove duplicate funnel subscribers, keeping the row that has
                    // progressed FURTHEST through the funnel sequence — not the row
                    // with the highest id. fc_funnel_subscribers is a state table,
                    // not a pure pivot: rows carry next_sequence_id, last_sequence_id,
                    // status, etc. A naive MAX(id) survivor can discard a row that
                    // already executed sequences in favor of a newer, less-advanced
                    // duplicate, which then re-fires sequences the contact already
                    // received (duplicate emails, duplicate webhooks, duplicate tag
                    // applications). Survivor rule: highest last_sequence_id wins;
                    // tiebreak by MAX(id) so we keep the newest row at the same
                    // progression level (which has the freshest timestamps and any
                    // post-progression status updates).
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    // IFNULL(last_sequence_id, 0) is critical: last_sequence_id
                    // is BIGINT UNSIGNED NULL and is genuinely NULL for freshly
                    // enrolled contacts that haven't run any sequence yet. Plain
                    // `t1.last_sequence_id = dups.max_seq` returns FALSE when
                    // both sides are NULL (SQL three-valued logic), so without
                    // the IFNULL the JOIN matches nothing for all-NULL duplicate
                    // groups and the dedupe silently leaves them in place —
                    // causing the subsequent ALTER ADD UNIQUE KEY to fail with
                    // "Duplicate entry". Normalizing NULL to 0 makes the
                    // comparison total: all-NULL rows compare equal and survivor
                    // selection falls through to MAX(id).
                    $deleted = $wpdb->query("DELETE fs FROM {$table} fs
                        INNER JOIN (
                            SELECT t1.funnel_id, t1.subscriber_id, MAX(t1.id) as keep_id
                            FROM {$table} t1
                            INNER JOIN (
                                SELECT funnel_id, subscriber_id, MAX(IFNULL(last_sequence_id, 0)) as max_seq
                                FROM {$table}
                                GROUP BY funnel_id, subscriber_id
                                HAVING COUNT(*) > 1
                            ) dups ON t1.funnel_id = dups.funnel_id
                                AND t1.subscriber_id = dups.subscriber_id
                                AND IFNULL(t1.last_sequence_id, 0) = dups.max_seq
                            GROUP BY t1.funnel_id, t1.subscriber_id
                        ) survivors ON fs.funnel_id = survivors.funnel_id
                            AND fs.subscriber_id = survivors.subscriber_id
                            AND fs.id != survivors.keep_id");

                    // If dedupe failed, leave the table as-is and skip ALTER
                    // so we don't fail with "Duplicate entry" silently again.
                    // Next migration run will retry.
                    if ($deleted === false) {
                        $dedupeOk = false;
                    }
                }

                if ($dedupeOk) {
                    if ($existingFunnelSubIdx) {
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $wpdb->query("ALTER TABLE {$table} DROP INDEX `funnel_subscriber_idx`, ADD UNIQUE KEY `funnel_subscriber_idx` (`funnel_id`, `subscriber_id`)");
                    } else {
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY `funnel_subscriber_idx` (`funnel_id`, `subscriber_id`)");
                    }
                }
            }

            // Add composite index for cron heartbeat query (runs every 60 seconds)
            if (!in_array('status_next_exec_idx', $indexNames)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$table} ADD INDEX `status_next_exec_idx` (`status`, `next_execution_time`)");
            }

        }
    }
}
