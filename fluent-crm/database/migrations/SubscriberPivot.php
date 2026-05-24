<?php

namespace FluentCrmMigrations;

class SubscriberPivot
{

    /**
     * Migrate the table.
     *
     * This table will maintain many-to-many relationships
     * between subscriber & lists and subscriber & tags.
     *
     * @return void
     */
    public static function migrate()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $table = $wpdb->prefix .'fc_subscriber_pivot';

        $subscriberTable = $wpdb->prefix .'fc_subscribers';

        $indexPrefix = $wpdb->prefix .'fc_srp_';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `subscriber_id` BIGINT UNSIGNED NOT NULL,
                `object_id` BIGINT UNSIGNED NOT NULL, /*list_id or tag_id*/
                `object_type` VARCHAR(50) NOT NULL, /*list or tag*/
                `status` VARCHAR(50) NULL,
                `is_public` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `{$indexPrefix}_sp_id_idx` (`subscriber_id` ASC),
                INDEX `{$indexPrefix}_sp_o_id_idx` (`object_id` ASC),
                INDEX `{$indexPrefix}_sp_t_id_idx` (`object_type` ASC),
                UNIQUE KEY `subscriber_object_type_unique` (`subscriber_id`, `object_id`, `object_type`(50))
            ) $charsetCollate;";

            dbDelta($sql);
            return;
        }

        // Existing table — ensure the composite unique key is present so that
        // attachTags/attachLists can rely on INSERT IGNORE for race-safe upsert.
        // The unique key prevents two concurrent attach paths (e.g. WooCommerce
        // + WP Fusion + LearnDash hooks firing on the same enrollment) from
        // both inserting a duplicate (subscriber_id, object_id, object_type)
        // row and both firing the corresponding contact_added_to_* action.
        //
        // The convergence logic mirrors the fixed FunnelSubscribers migration:
        // dedupe runs regardless of whether the index already exists in a
        // non-unique form or is missing entirely, because both states can hold
        // accumulated duplicates from past races.

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table");

        $existingUniqueIdx = null;
        foreach ($indexes as $index) {
            if ($index->Key_name === 'subscriber_object_type_unique') {
                $existingUniqueIdx = $index;
                break;
            }
        }
        $alreadyUnique = $existingUniqueIdx && (int)$existingUniqueIdx->Non_unique === 0;

        if ($alreadyUnique) {
            return;
        }

        // Sweep garbage rows before dedupe. subscriber_id and object_id are
        // BIGINT UNSIGNED NOT NULL in the schema, so NULLs aren't possible,
        // but 0 values are — these come from past bugs where the writer
        // didn't have a valid subscriber/list/tag ID. They're never meaningful
        // for the many-to-many relationship and would only block legitimate
        // future writes once the unique key is in place. Remove them outright.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DELETE FROM {$table} WHERE subscriber_id = 0 OR object_id = 0");

        // Pre-check duplicates so the heavy DELETE … JOIN is skipped on clean tables.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hasDuplicates = (int)$wpdb->get_var("SELECT COUNT(*) FROM (
            SELECT subscriber_id, object_id, object_type
            FROM {$table}
            GROUP BY subscriber_id, object_id, object_type
            HAVING COUNT(*) > 1
        ) dups");

        $dedupeOk = true;
        if ($hasDuplicates > 0) {
            // Keep the lowest id per (subscriber_id, object_id, object_type) group;
            // delete the rest. Lowest-id wins so the survivor has the earliest
            // created_at, matching the intuition "we already had this attachment."
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $deleted = $wpdb->query("DELETE sp FROM {$table} sp
                INNER JOIN (
                    SELECT subscriber_id, object_id, object_type, MIN(id) as keep_id
                    FROM {$table}
                    GROUP BY subscriber_id, object_id, object_type
                    HAVING COUNT(*) > 1
                ) dups ON sp.subscriber_id = dups.subscriber_id
                    AND sp.object_id = dups.object_id
                    AND sp.object_type = dups.object_type
                    AND sp.id != dups.keep_id");

            // If dedupe failed (lock wait timeout, missing privilege, etc.),
            // leave the table as-is and skip ALTER so we don't fail with
            // "Duplicate entry" silently. Next migration run will retry.
            if ($deleted === false) {
                $dedupeOk = false;
            }
        }

        if ($dedupeOk) {
            // object_type uses a 50-char prefix in the unique key — the column
            // is varchar(255) on older live tables (the original schema), and
            // a full-column composite key (subscriber_id BIGINT + object_id
            // BIGINT + object_type VARCHAR(255) utf8mb4) totals 1036 bytes,
            // exceeding the 767-byte InnoDB key length limit on MySQL builds
            // without innodb_large_prefix. Actual object_type values are class
            // FQCNs ≤ 28 chars, so a 50-char prefix enforces full uniqueness
            // with zero collision risk and fits within 50*4 + 8 + 8 = 216
            // bytes regardless of MySQL configuration.
            //
            // ALGORITHM=INPLACE, LOCK=NONE makes the ADD UNIQUE KEY an online
            // operation: InnoDB builds the new index in the background without
            // holding a metadata lock on the table. Without this, concurrent
            // queries (including front-end traffic that reads/writes the pivot
            // table) queue behind the ALTER and hit gateway timeouts on large
            // tables. Available on MySQL 5.6+ and MariaDB 10.0+ — every
            // platform WordPress runs on today.
            //
            // If the engine can't satisfy LOCK=NONE for any reason (older
            // server, FK constraints, etc.), the statement returns false
            // rather than falling back to a blocking ALTER. We log and skip
            // — operator can run the ALTER manually with their preferred
            // lock mode.
            if ($existingUniqueIdx) {
                // Index by this name exists but isn't unique — replace it.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $altered = $wpdb->query("ALTER TABLE {$table} DROP INDEX `subscriber_object_type_unique`, ADD UNIQUE KEY `subscriber_object_type_unique` (`subscriber_id`, `object_id`, `object_type`(50)), ALGORITHM=INPLACE, LOCK=NONE");
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $altered = $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY `subscriber_object_type_unique` (`subscriber_id`, `object_id`, `object_type`(50)), ALGORITHM=INPLACE, LOCK=NONE");
            }

            // Surface ALTER failures so they're not silently swallowed again.
            // We don't retry (the migrator bumps version regardless), but the
            // error_log gives ops a starting point if it happens.
            if ($altered === false && !empty($wpdb->last_error)) {
                error_log('FluentCRM: SubscriberPivot ADD UNIQUE KEY failed: ' . $wpdb->last_error);
            }
        }
    }
}
