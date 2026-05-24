<?php


namespace FluentCrm\App\Services\Libs\Mailer;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;

abstract class BaseHandler
{
    protected $startedAt = 0;

    protected $runnerTitle = '';

    protected $sentCount = 0;

    protected $maximumProcessingTime = 50;

    protected $calledFrom = 'cron';

    protected $startingTimeStamp = null;

    protected $optionKey = 'fluentcrm_is_sending_emails';

    protected $isMultiThread = false;

    protected $dispatchedWithinOneSecond = 0;

    protected $emailLimitPerSecond = 0;

    protected $sendingChunkNumber = 0;

    abstract protected function isTimeUp();

    protected function sendEmails($campaignEmails)
    {
        global $wpdb;
        do_action('fluent_crm/sending_emails_starting', $campaignEmails);

        if (defined('FLUENTMAIL')) {
            add_filter('fluentmail_will_log_email', 'fluentcrm_maybe_disable_fsmtp_log', 10, 2);
        }

        $failedIds = [];

        $this->sendingChunkNumber++;

        $sendableStatuses = ['subscribed', 'transactional'];
        $table = $wpdb->prefix . 'fc_campaign_emails';

        foreach ($campaignEmails as $email) {
            if ($this->reachedEmailLimitPerSecond()) {
                $this->updateEmailsStatus($failedIds, 'failed');
                $failedIds = [];
                $this->restartWhenOneSecondExceeds();
            }

            // Check again if the contact is in subscribed status or not
            // If not then we will cancel the email
            if ($email->subscriber && !in_array($email->subscriber->status, $sendableStatuses, true)) {
                $email->status = 'cancelled';
                $email->save();
                continue;
            }

            $emailData = $email->data();

            // for the same id
            if (Helper::wasProcessedByKeyId('mail_' . $email->id . '_' . $email->email_address)) {
                continue;
            }

            // Mark as 'sent' and clear email_body BEFORE sending.
            // This prevents duplicates on crash — if the process dies after this
            // point, the email won't be re-queued. Missing one email is acceptable,
            // sending duplicates is not.
            $wpdb->update($table, [
                'status'       => 'sent',
                'scheduled_at' => current_time('mysql'),
                'email_body'   => '',
                'is_parsed'    => 1,
            ], ['id' => $email->id]);

            if ($wpdb->last_error) {
                Helper::debugLog('DB Error at ' . $this->runnerTitle, $wpdb->last_error, 'error');
                return new \WP_Error('db_error', $wpdb->last_error);
            }

            $this->sentCount++;

            $response = Mailer::send($emailData, $email->subscriber, $email);

            $this->dispatchedWithinOneSecond++;

            // wp_mail() returns false on failure (not WP_Error) in most cases.
            // We must catch both to avoid marking undelivered emails as 'sent'.
            // Note: emails are marked 'sent' BEFORE wp_mail() by design to prevent
            // duplicate sends on crash. This is intentional — losing one email is
            // acceptable, sending duplicates is not.
            if (is_wp_error($response) || $response === false) {
                $failedIds[] = $email->id;
            }
        }

        $this->updateEmailsStatus($failedIds, 'failed');

        if (defined('FLUENTMAIL')) {
            remove_filter('fluentmail_will_log_email', 'fluentcrm_maybe_disable_fsmtp_log', 10);
        }

        do_action('fluentcrm_sending_emails_done', $campaignEmails);

        return true;
    }

    protected function processBatchEmails()
    {
        if ($this->isTimeUp()) {
            return 'time_up';
        }

        $emails = $this->getNextBatchEmails();

        if (!$emails || $emails->isEmpty()) {
            return 'empty';
        }

        $this->refreshLock();
        $result = $this->sendEmails($emails);

        if (is_wp_error($result)) {
            return $result;
        }

        usleep(10000); // 0.01 seconds sleep

        return $this->processBatchEmails();
    }

    abstract protected function getNextBatchEmails();

    protected function logSentCount()
    {
        if ($this->sentCount) {
            Helper::debugLog(sprintf($this->runnerTitle . ': Sent %d', $this->sentCount), sprintf('%d seconds via %s', time() - $this->startingTimeStamp, $this->calledFrom));
        }
    }

    /**
     * Memory exceeded
     *
     * Ensures the batch process never exceeds 90% of the maximum WordPress memory.
     *
     * Based on WP_Background_Process::memory_exceeded()
     *
     * @return bool
     */
    protected function memoryExceeded()
    {
        $memory_limit = fluentCrmGetMemoryLimit() * 0.70;
        $current_memory = memory_get_usage(true);

        $memory_exceeded = $current_memory >= $memory_limit;

        return apply_filters('fluentcrm_memory_exceeded', $memory_exceeded, $this);
    }

    protected function reachedEmailLimitPerSecond()
    {
        $emailLimitPerSecond = $this->getEmailLimitPerSecond();
        return ($emailLimitPerSecond && $this->dispatchedWithinOneSecond >= $emailLimitPerSecond);
    }

    protected function restartWhenOneSecondExceeds()
    {
        $elapsedTimeMicroSeconds = (microtime(true) - $this->startedAt) * 1000000;
        $remainingTimeMicroSeconds = 1000000 - $elapsedTimeMicroSeconds;

        if ($remainingTimeMicroSeconds > 0) {
            usleep((int)ceil($remainingTimeMicroSeconds));
            $seconds = number_format($remainingTimeMicroSeconds / 1000000, 4);
            Helper::debugLog('Restarting ' . $this->runnerTitle, 'Halt For: ' . $seconds . ' Seconds ' . $this->emailLimitPerSecond, 'info');
        }

        $this->dispatchedWithinOneSecond = 0;
        $this->startedAt = microtime(true);
    }

    protected function updateEmailsStatus($ids, $status)
    {
        if (!$ids) {
            return false;
        }

        global $wpdb;
        $whereIn = implode(',', array_fill(0, count($ids), '%d'));
        $query = "UPDATE {$wpdb->prefix}fc_campaign_emails SET status = %s WHERE id IN ($whereIn)";
        $wpdb->query($wpdb->prepare($query, array_merge([$status], $ids)));

        return true;
    }

    protected function handleFailedLog()
    {
        add_action('wp_mail_failed', function ($error) {
            $data = $error->get_error_data();
            $to = Arr::get($data, 'to');
            if ($to) {
                if (is_array($to)) {
                    $to = $to[0];
                }
            }

            if (!$to || !\is_string($to) || !is_email($to)) {
                return;
            }

            CampaignEmail::where('email_address', $to)
                ->limit(1)
                ->whereIn('status', ['processing', 'sent', 'failed'])
                ->orderBy('updated_at', 'DESC')
                ->update([
                    'status' => 'failed',
                    'note'   => $error->get_error_message()
                ]);
        });
    }

    protected function getEmailLimitPerSecond()
    {
        if ($this->emailLimitPerSecond) {
            return $this->emailLimitPerSecond;
        }

        $emailSettings = fluentcrmGetGlobalSettings('email_settings', []);

        if (!empty($emailSettings['emails_per_second'])) {
            $limit = (int)$emailSettings['emails_per_second'] - 3; // 3 is buffer
        } else {
            $limit = 14;
        }

        if (!$limit || $limit < 4) {
            $limit = 4;
        }

        if ($this->isMultiThread && $limit > 8) {
            $limit = ceil($limit / 2);
        }

        $limit = apply_filters('fluent_crm/email_limit_per_second', $limit, $emailSettings, $this);

        $this->emailLimitPerSecond = $limit;

        return $this->emailLimitPerSecond;
    }

    /**
     * Atomically acquire the processing lock.
     *
     * Replaces the old isProcessing() + processing() two-step pattern
     * which had a TOCTOU race condition — two processes could both read
     * "not processing" and both start sending emails.
     *
     * @return bool True if the lock was acquired, false if another process holds it.
     */
    protected function acquireLock()
    {
        $now = time();
        $lockTimeout = $this->maximumProcessingTime + 30;

        if (wp_using_ext_object_cache()) {
            // wp_cache_add() is atomic — only succeeds if key doesn't exist.
            // TTL handles auto-expiry of stuck locks.
            if (wp_cache_add($this->optionKey, $now, 'fc_instant_options', $lockTimeout)) {
                return true;
            }

            // Key exists — check if the lock has expired (process died)
            $existing = wp_cache_get($this->optionKey, 'fc_instant_options');
            if ($existing && ($now - (int)$existing) > $lockTimeout) {
                // Delete stale key, then re-acquire atomically via wp_cache_add()
                wp_cache_delete($this->optionKey, 'fc_instant_options');
                if (wp_cache_add($this->optionKey, $now, 'fc_instant_options', $lockTimeout)) {
                    return true;
                }
            }

            return false;
        }

        // Database path: single atomic UPDATE on wp_options
        global $wpdb;

        // Ensure the option row exists (INSERT IGNORE is idempotent)
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $this->optionKey, '', 'no'
        ));

        // Atomic: claim lock only if free (empty value) or expired (old timestamp)
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND (option_value = '' OR option_value < %d)",
            (string)$now, $this->optionKey, $now - $lockTimeout
        ));

        if ($affected > 0) {
            wp_cache_delete($this->optionKey, 'options');
            return true;
        }

        return false;
    }

    /**
     * Refresh the lock timestamp (heartbeat) to prevent stuck-lock detection.
     */
    protected function refreshLock()
    {
        $lockTimeout = $this->maximumProcessingTime + 30;
        Helper::setInstantOption($this->optionKey, time(), $lockTimeout);
    }

    /**
     * Release the processing lock so another process can acquire it.
     */
    protected function releaseLock()
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_delete($this->optionKey, 'fc_instant_options');
        } else {
            update_option($this->optionKey, '', false);
        }
    }
}
