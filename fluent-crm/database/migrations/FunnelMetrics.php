<?php

namespace FluentCrmMigrations;

class FunnelMetrics
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

        $table = $wpdb->prefix . 'fc_funnel_metrics';

        $indexPrefix = $wpdb->prefix . 'fc_fmx_';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = "CREATE TABLE $table (
                `id` BIGINT UNSIGNED NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `funnel_id` BIGINT UNSIGNED NULL,
                `sequence_id` BIGINT UNSIGNED NULL,
                `subscriber_id` BIGINT UNSIGNED NULL,
                `benchmark_value` BIGINT UNSIGNED DEFAULT 0,
                `benchmark_currency` VARCHAR(10) DEFAULT 'USD',
                `status` VARCHAR(50) DEFAULT 'completed',
                `notes` TEXT NULL,
                `created_at` TIMESTAMP NULL,
                `updated_at` TIMESTAMP NULL,
                INDEX `{$indexPrefix}_m_idx` (`funnel_id` ASC),
                INDEX `{$indexPrefix}_ms__idx` (`subscriber_id` ASC),
                KEY `sequence_id` (`sequence_id`),
                KEY `status` (`status`),
                UNIQUE KEY `funnel_seq_subscriber_unique` (`funnel_id`, `sequence_id`, `subscriber_id`)
            ) $charsetCollate;";
            dbDelta($sql);
        } else {

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $indexes = $wpdb->get_results("SHOW INDEX FROM $table");
            $indexedColumns = [];
            $indexNames = [];
            foreach ($indexes as $index) {
                $indexedColumns[] = $index->Column_name;
                $indexNames[] = $index->Key_name;
            }

            if(!in_array('sequence_id', $indexedColumns)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $indexSql = "ALTER TABLE {$table} ADD INDEX `sequence_id` (`sequence_id`),
                        ADD INDEX `status` (`status`);";
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query($indexSql);
            }

            // Add composite unique index for idempotency enforcement
            if (!in_array('funnel_seq_subscriber_unique', $indexNames)) {
                // Delete duplicate rows first, keeping the latest entry per group
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("DELETE fm FROM {$table} fm
                    INNER JOIN (
                        SELECT funnel_id, sequence_id, subscriber_id, MAX(id) AS keep_id
                        FROM {$table}
                        GROUP BY funnel_id, sequence_id, subscriber_id
                        HAVING COUNT(*) > 1
                    ) dups ON fm.funnel_id = dups.funnel_id
                        AND fm.sequence_id = dups.sequence_id
                        AND fm.subscriber_id = dups.subscriber_id
                        AND fm.id != dups.keep_id");

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE INDEX `funnel_seq_subscriber_unique` (`funnel_id`, `sequence_id`, `subscriber_id`)");
            }
        }
    }
}
