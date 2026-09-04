<?php

namespace FluentCrm\App\Services;


use FluentCrm\App\Models\CustomCompanyField;
use FluentCrm\App\Models\Lists;
use FluentCrm\App\Models\Tag;
use FluentCrm\Framework\Support\Arr;

class Sanitize
{
    public static function campaign($data)
    {
        $fieldMaps = [
            'title'            => 'sanitize_text_field',
            'slug'             => 'sanitize_text_field',
            'template_id'      => 'intval',
            'email_subject'    => 'sanitize_text_field',
            'email_pre_header' => 'sanitize_text_field',
            'utm_status'       => 'intval',
            'utm_source'       => 'sanitize_text_field',
            'utm_medium'       => 'sanitize_text_field',
            'utm_campaign'     => 'sanitize_text_field',
            'utm_term'         => 'sanitize_text_field',
            'utm_content'      => 'sanitize_text_field',
            'scheduled_at'     => 'sanitize_text_field',
            'design_template'  => 'sanitize_text_field'
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        if (!empty($data['settings']) && is_array($data['settings'])) {
            $data['settings'] = self::sanitizeCampaignSettings($data['settings']);
        }

        return $data;
    }

    private static function sanitizeCampaignSettings($settings)
    {
        if (!is_array($settings)) {
            return [];
        }

        $footerSettings = Arr::get($settings, 'footer_settings');
        if (is_array($footerSettings)) {
            $settings['footer_settings'] = self::sanitizeFooterSettings($footerSettings);
        }

        return $settings;
    }

    private static function sanitizeFooterSettings($footerSettings)
    {
        $fontSize = intval(Arr::get($footerSettings, 'font_size', 13));
        $footerPadding = intval(Arr::get($footerSettings, 'footer_padding', 20));
        $fontColor = sanitize_hex_color(Arr::get($footerSettings, 'font_color', '#202020')) ?: '#202020';
        $backgroundColor = Arr::get($footerSettings, 'background_color', 'transparent');
        $backgroundColor = ($backgroundColor === 'transparent')
            ? 'transparent'
            : (sanitize_hex_color($backgroundColor) ?: 'transparent');

        return [
            'disable_footer'   => Arr::get($footerSettings, 'disable_footer') === 'yes' ? 'yes' : 'no',
            'custom_footer'    => Arr::get($footerSettings, 'custom_footer') === 'yes' ? 'yes' : 'no',
            'footer_content'   => self::sanitizeFooterHtml(Arr::get($footerSettings, 'footer_content', '')),
            'font_size'        => min(24, max(8, $fontSize)),
            'font_color'       => $fontColor,
            'background_color' => $backgroundColor,
            'footer_padding'   => min(80, max(0, $footerPadding))
        ];
    }

    public static function sanitizeFooterHtml($footerHtml)
    {
        if (!is_scalar($footerHtml)) {
            return '';
        }

        $footerHtml = (string) $footerHtml;
        if ($footerHtml === '') {
            return '';
        }

        $allowedTags = wp_kses_allowed_html('post');
        $styleAllowedTags = [
            'a', 'p', 'div', 'span', 'img', 'ul', 'ol', 'li',
            'strong', 'em', 'b', 'i', 'u', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'table', 'tbody', 'thead', 'tfoot', 'tr', 'td', 'th', 'blockquote'
        ];

        foreach ($styleAllowedTags as $tagName) {
            if (!isset($allowedTags[$tagName])) {
                $allowedTags[$tagName] = [];
            }
            $allowedTags[$tagName]['style'] = [];
        }

        if (isset($allowedTags['a'])) {
            $allowedTags['a']['target'] = [];
            $allowedTags['a']['rel'] = [];
        }

        if (isset($allowedTags['img'])) {
            $allowedTags['img']['loading'] = [];
            $allowedTags['img']['decoding'] = [];
        }

        return wp_kses($footerHtml, $allowedTags);
    }

    /**
     * Remove control characters that WordPress's sanitizers let through.
     *
     * sanitize_text_field() folds tab, newline and carriage return into spaces
     * but leaves the remaining C0 controls — including NUL — untouched, so a
     * value like "before\0after" round-trips through the API intact. A NUL byte
     * truncates C-string comparisons, corrupts CSV and JSON exports, and can
     * split a value differently in PHP than in MySQL.
     *
     * Tab, newline and carriage return are deliberately preserved: they are
     * legitimate inside the multi-line fields that route through wp_kses_post
     * rather than sanitize_text_field, and stripping them there would damage
     * real content.
     *
     * The pattern is intentionally **not** /u. These are all single-byte ASCII
     * controls, a bytewise replace cannot corrupt a multi-byte sequence (UTF-8
     * continuation bytes are all >= 0x80), and the /u modifier would make
     * preg_replace() return null for input that is not already valid UTF-8.
     *
     * @param mixed $value Scalar or nested array; anything else is returned as-is.
     * @return mixed
     */
    private static function stripControlCharacters($value)
    {
        if (is_array($value)) {
            return array_map([__CLASS__, 'stripControlCharacters'], $value);
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        $stripped = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // preg_replace() returns null on failure; never silently drop the value.
        return $stripped === null ? $value : $stripped;
    }

    public static function contact($data)
    {
        $fieldMaps = [
            'hash'            => 'sanitize_text_field',
            'prefix'          => 'sanitize_text_field',
            'first_name'      => 'sanitize_text_field',
            'last_name'       => 'sanitize_text_field',
            'user_id'         => 'intval',
            'email'           => 'sanitize_email',
            'status'          => 'sanitize_text_field',
            'contact_type'    => 'sanitize_text_field',
            'address_line_1'  => 'sanitize_text_field',
            'address_line_2'  => 'sanitize_text_field',
            'postal_code'     => 'sanitize_text_field',
            'city'            => 'sanitize_text_field',
            'state'           => 'sanitize_text_field',
            'country'         => 'sanitize_text_field',
            'phone'           => 'sanitize_text_field',
            'timezone'        => 'sanitize_text_field',
            'date_of_birth'   => 'sanitize_text_field',
            'source'          => 'sanitize_text_field',
            'life_time_value' => 'sanitize_text_field',
            'last_activity'   => 'sanitize_text_field',
            'total_points'    => 'intval',
            'latitude'        => 'sanitize_text_field',
            'longitude'       => 'sanitize_text_field',
            'ip'              => 'sanitize_text_field',
            'created_at'      => 'sanitize_text_field',
            'updated_at'      => 'sanitize_text_field',
            'avatar'          => 'esc_url_raw',
            'company_id'      => 'intval',
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        if (isset($data['status'])) {
            $status = $data['status'];
            if (!in_array($status, fluentcrm_subscriber_statuses())) {
                unset($data['status']);
            }
        }

        if (isset($data['contact_type'])) {
            if (!array_key_exists($data['contact_type'], fluentcrm_contact_types())) {
                unset($data['contact_type']);
            }
        }

        return $data;
    }

    /**
     * Sanitize custom contact field values before they are persisted.
     *
     * Custom fields are stored as free-form meta rows rather than mapped columns,
     * so self::contact() cannot cover them from a static field map — the set of
     * keys is defined by the site owner at runtime. The field's own configured
     * type decides the rule instead:
     *
     *   - textarea            wp_kses_post, matching contactNote()'s description,
     *                         so multi-line fields keep safe formatting HTML
     *   - number              numeric cast, preserving int vs float
     *   - checkbox / *-select array of sanitize_text_field
     *   - everything else     sanitize_text_field
     *
     * A key with no matching field definition is treated as text. Unregistered
     * keys are still written (integrations rely on ad-hoc keys), but an unknown
     * key is never a reason to trust the value.
     *
     * @param array $values slug => value, as submitted
     * @param array $fields optional pre-loaded field definitions keyed by slug,
     *                      to avoid re-reading the option in a loop
     * @return array sanitized values, keys preserved
     */
    public static function contactCustomValues($values, $fields = [])
    {
        return self::customFieldValues($values, 'contact_custom_fields', $fields);
    }

    /**
     * Sanitize custom company field values before they are persisted.
     *
     * Same rules and the same reasoning as self::contactCustomValues() — only the
     * option holding the field definitions differs. Company custom values live in
     * the serialized `meta` column instead of their own rows, and every write path
     * (REST create/update, CSV import, the public Companies API) reaches them
     * through Api\Classes\Companies::createOrUpdate(), which is where this runs.
     *
     * @param array $values slug => value, as submitted
     * @param array $fields optional pre-loaded field definitions keyed by slug
     * @return array sanitized values, keys preserved
     */
    public static function companyCustomValues($values, $fields = [])
    {
        return self::customFieldValues($values, 'company_custom_fields', $fields);
    }

    /**
     * Shared implementation behind the contact and company custom-value sanitizers.
     *
     * @param array  $values     slug => value, as submitted
     * @param string $optionName fluentcrm option holding the field definitions
     * @param array  $fields     optional pre-loaded definitions keyed by slug
     * @return array
     */
    protected static function customFieldValues($values, $optionName, $fields = [])
    {
        if (!$values || !is_array($values)) {
            return $values;
        }

        if (!$fields) {
            foreach ((array)fluentcrm_get_option($optionName, []) as $field) {
                if (!empty($field['slug'])) {
                    $fields[$field['slug']] = $field;
                }
            }
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            $key = sanitize_text_field($key);
            $type = Arr::get($fields, $key . '.type', 'text');

            if (is_array($value)) {
                // checkbox and multi-select arrive as arrays; the values are
                // choice labels, so plain text is always correct here.
                $sanitized[$key] = array_values(array_map(function ($item) {
                    return is_scalar($item) ? sanitize_text_field($item) : '';
                }, $value));
                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                // Objects/resources have no valid representation in a meta value.
                $sanitized[$key] = '';
                continue;
            }

            $value = (string)$value;

            if ($type === 'textarea') {
                $sanitized[$key] = wp_kses_post($value);
                continue;
            }

            if ($type === 'number') {
                $sanitized[$key] = ($value === '') ? '' : $value + 0;
                continue;
            }

            $sanitized[$key] = sanitize_text_field($value);
        }

        // Custom values reach the same sanitizers as the mapped columns, so they
        // inherit the same control-character gap. Applied once over the finished
        // set rather than at each branch above; the helper recurses into the
        // array values that checkbox and multi-select fields produce.
        return self::stripControlCharacters($sanitized);
    }

    public static function contactNote($data)
    {
        $fieldMaps = [
            'subscriber_id' => 'intval',
            'parent_id'     => 'intval',
            'created_by'    => 'sanitize_text_field',
            'status'        => 'sanitize_text_field',
            'type'          => 'sanitize_text_field',
            'title'         => 'sanitize_text_field',
            'description'   => 'wp_kses_post',
            'created_at'    => 'sanitize_text_field'
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        return $data;
    }

    public static function funnel($data)
    {
        $fieldMaps = [
            'type'         => 'sanitize_text_field',
            'title'        => 'sanitize_text_field',
            'trigger_name' => 'sanitize_text_field',
            'status'       => 'sanitize_text_field',
            'created_by'   => 'intval',
            'updated_at'   => 'sanitize_text_field'
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        return $data;
    }

    public static function company($data)
    {
        $fieldMaps = [
            'name'             => 'sanitize_text_field',
            'description'      => 'wp_kses_post',
            'phone'            => 'sanitize_text_field',
            'email'            => 'sanitize_email',
            'owner_id'         => 'intval',
            'employees_number' => 'intval',
            'industry'         => 'sanitize_text_field',
            'type'             => 'sanitize_text_field',
            'address_line_1'   => 'sanitize_text_field',
            'address_line_2'   => 'sanitize_text_field',
            'postal_code'      => 'sanitize_text_field',
            'city'             => 'sanitize_text_field',
            'state'            => 'sanitize_text_field',
            'country'          => 'sanitize_text_field',
            'website'          => 'esc_url_raw',
            'linkedin_url'     => 'esc_url_raw',
            'facebook_url'     => 'esc_url_raw',
            'twitter_url'      => 'esc_url_raw',
            'logo'             => 'esc_url_raw',
        ];

        foreach ($data as $key => $value) {
            if ($value && isset($fieldMaps[$key]) && !is_array($value)) {
                $data[$key] = self::stripControlCharacters(
                    call_user_func($fieldMaps[$key], $value)
                );
            }
        }

        if (isset($data['custom_values'])) {
            $customValues = Arr::get($data, 'custom_values', []);

            $customFieldKeys = [];
            $customFields = (new CustomCompanyField())->getGlobalFields()['fields'];

            foreach ($customFields as $field) {
                $customFieldKeys[] = $field['slug'];
            }

            if ($customFieldKeys) {
                if ($customValues) {
                    $customValues = (new CustomCompanyField)->formatCustomFieldValues($customValues);
                }
            }

            $data['custom_values'] = $customValues;
        }

        return $data;
    }

    public static function sanitizeTagIds($inputTagIds, $willCreate = true)
    {
        if (!$inputTagIds) {
            return [];
        }

        $tagIds = [];
        $nonNumericIds = [];

        foreach ($inputTagIds as $tagId) {
            if (is_numeric($tagId)) {
                $tagIds[] = (int)$tagId;
            } else {
                $nonNumericIds[] = $tagId;
            }
        }

        if (!$nonNumericIds) {
            return $tagIds;
        }

        foreach ($nonNumericIds as $maybeNewTag) {
            if (strlen($maybeNewTag) < 3) {
                continue;
            }

            $exit = Tag::where('title', $maybeNewTag)
                ->orWhere('slug', $maybeNewTag)
                ->first();

            if ($exit) {
                $tagIds[] = $exit->id;
                continue;
            }

            if (!$willCreate) {
                continue;
            }

            // Let's create a new
            $tag = Tag::create([
                'title' => $maybeNewTag,
                'slug'  => sanitize_title($maybeNewTag)
            ]);

            $tagIds[] = $tag->id;
            do_action('fluent_crm/tag_created', $tag);
        }

        return $tagIds;
    }

    public static function sanitizeListIds($inputListIds, $willCreate = true)
    {
        if (!$inputListIds) {
            return [];
        }

        $listIds = [];
        $nonNumericIds = [];

        foreach ($inputListIds as $listId) {
            if (is_numeric($listId)) {
                $listIds[] = (int)$listId;
            } else {
                $nonNumericIds[] = $listId;
            }
        }

        if (!$nonNumericIds) {
            return $listIds;
        }

        foreach ($nonNumericIds as $maybeNewList) {
            if (strlen($maybeNewList) < 3) {
                continue;
            }

            $exit = Lists::where('title', $maybeNewList)
                ->orWhere('slug', $maybeNewList)
                ->first();

            if ($exit) {
                $listIds[] = $exit->id;
                continue;
            }

            if (!$willCreate) {
                continue;
            }

            // Let's create a new
            $list = Lists::create([
                'title' => sanitize_text_field($maybeNewList),
                'slug'  => sanitize_title($maybeNewList)
            ]);

            $listIds[] = $list->id;
            do_action('fluent_crm/list_created', $list);
        }

        return $listIds;
    }
}
