<?php

namespace FluentCrm\App\Services\Libs\Mailer;

use FluentCrm\App\Models\CampaignEmail;
use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Support\Arr;
use FluentCrm\Framework\Support\Collection;
use FluentCrm\Framework\Support\Str;

class Handler extends BaseHandler
{
    protected $runnerTitle = 'Handler::handle';

    protected $sendingPerChunk = 20;

    protected $maximumProcessingTime = 50;

    protected $optionKey = 'fluentcrm_is_sending_emails';

    public function __construct()
    {
        /**
         * The default mailer chunk size for the main email handler.
         *
         * @param int $sendingPerChunk Number of campaign emails pulled per batch. Default is 20.
         * @return int
         */
        $sendingPerChunk = (int)apply_filters('fluent_crm/mailer_handler_chunk_size', $this->sendingPerChunk);
        if ($sendingPerChunk > 0) {
            $this->sendingPerChunk = $sendingPerChunk;
        }

        /**
         * The maximum processing window (seconds) for the main email handler.
         *
         * @param int $maximumProcessingTime Max loop runtime in seconds. Default is 50.
         * @return int
         */
        $maximumProcessingTime = (int)apply_filters('fluent_crm/mailer_handler_max_processing_seconds', $this->maximumProcessingTime);
        if ($maximumProcessingTime > 0) {
            $this->maximumProcessingTime = $maximumProcessingTime;
        }
    }

    public function handle()
    {
        if (!$this->isSystemOk()) {
            return true; // Early return
        }

        Helper::debugLog('Running Scheduler -> ' . $this->calledFrom, 'Handler::handle');

        Helper::maybeDisableEmojiOnEmail();

        try {
            $this->handleFailedLog();
            $this->startedAt = microtime(true);
            $result = $this->processBatchEmails();

            if (is_wp_error($result)) {
                Helper::debugLog('Error at Mailer::handle', $result->get_error_message(), 'error');
                $this->releaseLock();
                $this->logSentCount();
                return true;
            }

            if ($result === 'time_up') {
                $this->releaseLock();
                $this->callBackGround();
                $this->logSentCount();
                return true;
            }
        } catch (\Exception $e) {
            Helper::debugLog('Exception at Mailer::handle', $e->getMessage(), 'error');
        }

        $this->releaseLock();
        $this->logSentCount();

        if ($this->sentCount || random_int(0, 50) > 20) { // sometimes we want to check this
            $lastChecked = fluentCrmGetOptionCache('_fcrm_last_email_process_cleanup', 600);
            if (!$lastChecked || time() - $lastChecked > 70) {
                $dateStamp = gmdate('Y-m-d H:i:s', (current_time('timestamp') - $this->maximumProcessingTime - 30));
                CampaignEmail::where('status', 'processing')
                    ->where('updated_at', '<', $dateStamp)
                    ->update([
                        'status' => 'pending'
                    ]);
                fluentCrmSetOptionCache('_fcrm_last_email_process_cleanup', time(), 600);
            }
        }

        if (!$this->sentCount) {
            do_action('fluentcrm_scheduled_maybe_regular_tasks');
            do_action('fluent_crm_process_automation');
        }

        return true;
    }

    private function isSystemOk()
    {
        $this->calledFrom = Arr::get($_REQUEST, 'action') == 'fluentcrm-post-campaigns-send-now' ? 'ajax' : 'cron';

        fluentcrm_update_option($this->optionKey . '_last_called', time());

        if (did_action('fluent_crm/sending_emails_starting') || apply_filters('fluent_crm/disable_email_processing', false)) {
            return false;
        }

        $this->startingTimeStamp = time();
        $this->isMultiThread = Helper::willMultiThreadEmail();

        if ($this->isMultiThread) {
            if (!as_has_scheduled_action('fluent_crm_send_multi_thread_emails', [], 'fluent-crm')) {
                Helper::debugLog('Scheduling multi thread emails', 'extended log');
                as_schedule_recurring_action(time(), 60, 'fluent_crm_send_multi_thread_emails', [], 'fluent-crm', false);
            }
        }

        if ($this->memoryExceeded()) {
            Helper::debugLog('Mailer Memory Exceeded at ' . $this->runnerTitle, 'Memory Limit: ' . fluentCrmGetMemoryLimit() . '<br />Current Usage: ' . memory_get_usage(true));
            return false;
        }

        // Extend PHP execution time to give the handler enough headroom.
        // The handler has its own isTimeUp() check and will stop gracefully
        // within maximumProcessingTime seconds, but PHP's max_execution_time
        // (often 30s in web context) can kill the process before that.
        if (function_exists('set_time_limit')) {
            @set_time_limit($this->maximumProcessingTime + 30);
        }

        $systemMaxProcessingTime = fluentCrmMaxRunTime();

        if ($this->maximumProcessingTime > $systemMaxProcessingTime) {
            $this->maximumProcessingTime = $systemMaxProcessingTime;
        }

        if (!$this->acquireLock()) {
            return false;
        }

        return true;
    }

    protected function getNextBatchEmails()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'fc_campaign_emails';
        $currentTime = current_time('mysql');

        // Atomic claim: SELECT ids then UPDATE status in a transaction.
        // The status check in the UPDATE WHERE clause prevents double-claiming
        // if another handler somehow selects the same rows.
        $wpdb->query('START TRANSACTION');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status IN ('pending', 'scheduled') AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT %d FOR UPDATE",
            $currentTime, $this->sendingPerChunk
        ));

        $ids = wp_list_pluck($rows, 'id');

        if ($ids) {
            $idsPlaceholder = implode(',', array_fill(0, count($ids), '%d'));
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id IN ($idsPlaceholder) AND status IN ('pending', 'scheduled')",
                array_merge([$currentTime], $ids)
            ));

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return new Collection([]);
            }
        }

        $wpdb->query('COMMIT');

        if (!$ids) {
            return new Collection([]);
        }

        // Only return rows we actually claimed (status = processing)
        return CampaignEmail::whereIn('id', $ids)
            ->where('status', 'processing')
            ->with(['campaign', 'subscriber'])
            ->get();
    }

    public function processSubscriberEmail($subscriberId)
    {
        if (!$this->isSystemOk()) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'fc_campaign_emails';
        $currentTime = current_time('mysql');

        $wpdb->query('START TRANSACTION');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$table} WHERE status IN ('pending', 'scheduled') AND scheduled_at <= %s AND scheduled_at IS NOT NULL AND subscriber_id = %d FOR UPDATE",
            $currentTime, $subscriberId
        ));

        $ids = wp_list_pluck($rows, 'id');

        if ($ids) {
            $idsPlaceholder = implode(',', array_fill(0, count($ids), '%d'));
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id IN ($idsPlaceholder) AND status IN ('pending', 'scheduled')",
                array_merge([$currentTime], $ids)
            ));

            if ($result === false) {
                $wpdb->query('ROLLBACK');
                $this->releaseLock();
                return;
            }
        }

        $wpdb->query('COMMIT');

        if ($ids) {
            $emailCollection = CampaignEmail::whereIn('id', $ids)
                ->where('status', 'processing')
                ->with('campaign', 'subscriber')
                ->get();

            $this->sendEmails($emailCollection);
        }

        $this->releaseLock();
    }

    public function sendDoubleOptInEmail($subscriber)
    {
        if ($subscriber->status == 'subscribed' || !$subscriber->email) {
            return false; // already subscribed
        }

        $listIdOfSubscriber = Helper::latestListIdOfSubscriber($subscriber->id);
        $config = null;
        if ($listIdOfSubscriber) {
            $globalDoubleOptin = fluentcrm_get_list_meta($listIdOfSubscriber, 'global_double_optin');
            if ($globalDoubleOptin && $globalDoubleOptin->value == 'no') {
                $meta = fluentcrm_get_meta($listIdOfSubscriber, 'FluentCrm\App\Models\Lists', 'double_optin_settings');
                $config = $meta ? $meta->value : null;
            }
        }

        if (!$config) {
            $config = Helper::getDoubleOptinSettings();
        }

        if (!Arr::get($config, 'email_subject') || !Arr::get($config, 'email_body')) {
            return false; // is not valid
        }

        $emailBody = apply_filters('fluent_crm/parse_campaign_email_text', $config['email_body'], $subscriber);
        $emailSubject = apply_filters('fluent_crm/parse_campaign_email_text', $config['email_subject'], $subscriber);

        $emailPreHeader = '';
        if (Arr::get($config, 'email_pre_header')) {
            $emailPreHeader = apply_filters('fluent_crm/parse_campaign_email_text', $config['email_pre_header'], $subscriber);
        }

        $url = site_url('?fluentcrm=1&route=confirmation&hash=' . $subscriber->hash . '&secure_hash=' . $subscriber->getSecureHash());

        $emailBody = apply_filters('fluent_crm/double_optin_email_body', $emailBody, $subscriber);
        $emailSubject = apply_filters('fluent_crm/double_optin_email_subject', $emailSubject, $subscriber);
        $emailPreHeader = apply_filters('fluent_crm/double_optin_email_pre_header', $emailPreHeader, $subscriber);

        $emailBody = str_replace('#activate_link#', $url, $emailBody);

        $templateData = [
            'preHeader'   => $emailPreHeader,
            'email_body'  => $emailBody,
            'footer_text' => '',
            'config'      => Helper::getTemplateConfig($config['design_template'], false)
        ];

        $emailBody = apply_filters(
            'fluent_crm/email-design-template-' . $config['design_template'],
            $emailBody,
            $templateData,
            false,
            $subscriber
        );

        if (Str::contains($emailBody, ['##crm.', '{{crm.'])) {
            // we have CRM specific smartcodes
            $emailBody = apply_filters('fluent_crm/parse_extended_crm_text', $emailBody, $subscriber);
        }

        $data = [
            'to'      => [
                'email' => $subscriber->email,
                'name'  => $subscriber->full_name
            ],
            'subject' => $emailSubject,
            'body'    => $emailBody,
            'headers' => Helper::getMailHeader(),
            'scope'   => 'double_optin'
        ];

        Helper::maybeDisableEmojiOnEmail();
        Mailer::send($data, $subscriber);
        return true;
    }

    private function callBackGround()
    {
        if ($this->memoryExceeded()) {
            Helper::debugLog('Handler::callBackGround Memory Exceeded', 'Memory Limit: ' . fluentCrmGetMemoryLimit() . '<br />Current Usage: ' . memory_get_usage(true), 'info');
            return false;
        }

        $nextCron = as_next_scheduled_action('fluentcrm_scheduled_every_minute_tasks');
        $willRun = !$nextCron || $nextCron == 1 || ($nextCron - time()) >= 5 || ($nextCron - time()) < -70;

        if (!$willRun) {
            $lastCalled = (int)fluentcrm_get_option($this->optionKey . '_last_called');
            if ($lastCalled && (time() - $lastCalled) < 50) {
                $willRun = true;
            }
        }

        if ($willRun) {

            $url = add_query_arg([
                'action' => 'fluentcrm-post-campaigns-send-now',
                'time'   => time()
            ], admin_url('admin-ajax.php'));

            Helper::debugLog('Sent to Background Handler::callBackGround', $url, 'extended');

            self::fireNonBlockingRequest($url, [
                'campaign_id' => null,
                'retry'       => 1
            ]);
        } else {
            Helper::debugLog('Not Running', 'Handler::callBackGround -> ' . ($nextCron - time()), 'extended');
        }
    }

    protected function isTimeUp()
    {
        return (time() - $this->startingTimeStamp) >= $this->maximumProcessingTime;
    }

    /**
     * Fire a non-blocking POST request using cURL directly.
     *
     * Bypasses WordPress's WP_Http which adds SSL verification filters
     * that break loopback requests on local/self-signed cert environments.
     * Connection timeout is 1 second — we don't wait for the response.
     *
     * @param string $url
     * @param array $body POST body data
     */
    public static function fireNonBlockingRequest($url, $body = [])
    {
        if (!function_exists('curl_init')) {
            // Fallback to wp_remote_post if cURL not available
            add_filter('https_local_ssl_verify', '__return_false');
            wp_remote_post($url, [
                'sslverify' => false,
                'blocking'  => false,
                'timeout'   => 1,
                'body'      => $body
            ]);
            remove_filter('https_local_ssl_verify', '__return_false');
            return;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        // Fire and forget — we don't need the response
        curl_exec($ch);
        curl_close($ch);
    }
}
