<?php

namespace FluentCrm\App\Services;

use FluentCrm\Framework\Support\Arr;

class RemoteTemplateFetcher
{
    const MAX_TEMPLATE_SIZE = 1048576;

    /**
     * Download and parse a trusted FluentCRM template JSON file.
     *
     * @param string $fileUrl
     * @return array|\WP_Error
     */
    public static function fetch($fileUrl)
    {
        $fileUrl = is_string($fileUrl) ? esc_url_raw($fileUrl) : '';

        if (!$fileUrl || !static::isAllowedUrl($fileUrl)) {
            return new \WP_Error(
                'invalid_template_source',
                __('Invalid template source URL', 'fluent-crm')
            );
        }

        $response = wp_remote_get($fileUrl, [
            'sslverify'           => true,
            'timeout'             => 20,
            'redirection'         => 0,
            'limit_response_size' => static::MAX_TEMPLATE_SIZE
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'template_download_failed',
                __('Unable to download the selected template. Please try again.', 'fluent-crm')
            );
        }

        $responseCode = wp_remote_retrieve_response_code($response);
        if ($responseCode < 200 || $responseCode >= 300) {
            return new \WP_Error(
                'template_download_failed',
                __('Unable to download the selected template. Please try again.', 'fluent-crm')
            );
        }

        return static::parseJson(wp_remote_retrieve_body($response));
    }

    /**
     * Parse and validate raw FluentCRM template JSON.
     *
     * @param string $content
     * @return array|\WP_Error
     */
    public static function parseJson($content)
    {
        if (!is_string($content) || strlen($content) > static::MAX_TEMPLATE_SIZE) {
            return static::invalidTemplateJson();
        }

        $payloads = [$content];
        $unslashed = wp_unslash($content);
        if ($unslashed !== $content) {
            $payloads[] = $unslashed;
        }

        foreach ($payloads as $payload) {
            $data = json_decode($payload, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($data) && Arr::get($data, 'is_fc_template') === 'yes') {
                return $data;
            }
        }

        return static::invalidTemplateJson();
    }

    /**
     * Restrict direct template downloads to trusted FluentCRM template hosts.
     *
     * @param string $url
     * @return bool
     */
    public static function isAllowedUrl($url)
    {
        $parsedUrl = wp_parse_url($url);

        if (empty($parsedUrl['scheme']) || empty($parsedUrl['host']) || $parsedUrl['scheme'] !== 'https') {
            return false;
        }

        return in_array(strtolower($parsedUrl['host']), static::getAllowedHosts(), true);
    }

    /**
     * @return array
     */
    public static function getAllowedHosts()
    {
        $allowedHosts = [
            'fluentcrm.com',
            'www.fluentcrm.com',
            'wpmanageninja.com',
            'www.wpmanageninja.com'
        ];

        if (defined('FC_TEMPLATE_API_DOMAIN')) {
            $configuredHost = wp_parse_url(FC_TEMPLATE_API_DOMAIN, PHP_URL_HOST);
            if ($configuredHost) {
                $allowedHosts[] = strtolower($configuredHost);
            }
        }

        $allowedHosts = apply_filters('fluent_crm/remote_template_allowed_hosts', $allowedHosts);

        if (!is_array($allowedHosts)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(function ($host) {
            return is_scalar($host) ? strtolower(sanitize_text_field($host)) : '';
        }, $allowedHosts))));
    }

    /**
     * @return \WP_Error
     */
    protected static function invalidTemplateJson()
    {
        return new \WP_Error(
            'invalid_template_json',
            __('The provided JSON file is not valid.', 'fluent-crm')
        );
    }
}
