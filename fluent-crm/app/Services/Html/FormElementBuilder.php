<?php

namespace FluentCrm\App\Services\Html;

use FluentCrm\Framework\Support\Arr;

class FormElementBuilder
{

    public function renderFields($fields, $print = false)
    {
        $this->addExternalScriptsAndCss();
        $html = '';
        foreach ($fields as $field) {
            $html .= $this->renderField($field);
        }
        if ($print) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return $html;
    }

    public function renderField($field)
    {
        $type = Arr::get($field, 'type');
        if ($type == 'container') {
            return $this->renderContainer($field);
        }

        if ($type == 'raw_html') {
            return (string) Arr::get($field, 'html');
        }

        if ($type == 'hidden') {
            $atts = $this->buildAttributes($field['atts']);
            return '<input type="hidden" ' . $atts . '"/>';
        }

        $inputHtml = '';

        if ($type == 'input') {
            $inputHtml = $this->renderInput($field);
        } else if ($type == 'select') {
            $inputHtml = $this->renderSelect($field);
        } else if ($type == 'select-multi') {
            $inputHtml = $this->renderMultiSelect($field);
        } else if ($type == 'radio') {
            $inputHtml = $this->renderRadio($field);
        } else if ($type == 'checkboxes') {
            $inputHtml = $this->renderCheckboxes($field);
        } else if ($type == 'date') {
            $inputHtml = $this->renderDate($field);
        } else if ($type == 'textarea') {
            $inputHtml = $this->renderTextarea($field);
        } else if ($type == 'number') {
            $inputHtml = $this->renderInput($field);
        } else if ($type == 'custom_date') {
            $inputHtml = $this->renderDatePicker($field);
        } else if ($type == 'custom_date_time') {
            $inputHtml = $this->renderDateTimePicker($field);
        } else if ($type == 'date_dropdowns') {
            $inputHtml = $this->renderDateDropdowns($field);
        }

        return $this->renderLabel($field, $inputHtml);
    }

    public function renderSelect($field)
    {
        $atts = $this->buildAttributes([
            'id' => Arr::get($field, 'id'),
            'name' => Arr::get($field, 'name'),
        ]);

        $html = '<select ' . $atts . '>';

        if ($placeholder = Arr::get($field, 'placeholder')) {
            $selected = $field['value'] ? '' : 'selected';
            $html .= '<option ' . $selected . ' value="">' . esc_html($placeholder) . '</option>';
        }

        foreach ($field['options'] as $key => $label) {
            $selected = ($key == $field['value']) ? 'selected' : '';
            $html .= '<option ' . $selected . ' value="' . esc_html($key) . '">' . esc_html($label) . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    public function renderRadio($field)
    {
        $name = $field['name'];
        $html = '<div class="fc_radio_buttons">';

        foreach ($field['options'] as $key => $label) {
            $attributes = [
                'type' => 'radio',
                'name' => $name,
                'value' => $key
            ];

            if ($key == $field['value']) {
                $attributes['checked'] = true;
            }

            $html .= '<label class="fc_radio_item">';
            $html .= '<input ' . $this->buildAttributes($attributes) . ' /> ' . esc_html($label);
            $html .= '</label>';
        }

        $html .= '</div>';

        return $html;
    }

    public function renderCheckboxes($field)
    {
        $name = $field['name'];
        $options = $field['options'];
        $selectedValues = (array) $field['value'];

        $html = '<div class="fc_checkboxes">';

        $isAssoc = array_keys($options) !== range(0, count($options) - 1);

        foreach ($options as $optionKey => $list_option) {
            $optionValue = $isAssoc ? $optionKey : $list_option;

            $attrbutes = [
                'type'  => 'checkbox',
                'name'  => esc_attr($name) . '[]',
                'value' => esc_attr($optionValue)
            ];

            if (in_array($optionValue, $selectedValues)) {
                $attrbutes['checked'] = true;
            }

            $html .= '<label class="fc_list_items">';
            $html .= '<input ' . $this->buildAttributes($attrbutes) . ' /> ' . esc_html($list_option);
            $html .= '</label>';
        }

        $html .= '</div>';
        return $html;
    }

    public function renderContainer($field)
    {
        $innerFields = Arr::get($field, 'fields', []);
        if (!$innerFields) {
            return '';
        }

        $html = '<div class="fc_field_container ' . esc_attr(Arr::get($field, 'container_class')) . '">';
        $html .= $this->renderFields($innerFields);
        $html .= '</div>';
        return $html;
    }

    public function renderLabel($field, $innerHtml = '')
    {
        $containerClass = 'fc_field fc_field_' . $field['name'] . ' fc_field_' . $field['type'];

        if ($givenClass = Arr::get($field, 'container_class')) {
            $containerClass .= ' ' . $givenClass;
        }

        $html = '<div class="' . esc_attr($containerClass) . '">';

        if ($label = Arr::get($field, 'label')) {
            if ($id = Arr::get($field, 'id')) {
                // date_dropdowns: label must target first visible select, not the hidden input
                $forId = (Arr::get($field, 'type') === 'date_dropdowns') ? $id . '_day' : $id;
                $labelAtts = $this->buildAttributes([
                    'for' => $forId
                ]);
            } else {
                $labelAtts = '';
            }
            $required = '';
            if (Arr::get($field, 'required')) {
                $required = ' <span class="fc_required_mark">*</span>';
            }

            $html .= '<label ' . $labelAtts . '>' . esc_html($label) . $required . '</label>';
        }

        return $html . $innerHtml . '</div>';
    }

    public function renderInput($field)
    {
        $atts = Arr::get($field, 'atts', []);
        $atts['name'] = $field['name'];

        if (!empty($field['required'])) {
            $atts['required'] = true;
        }

        if (!empty($field['id'])) {
            $atts['id'] = $field['id'];
        }

        if (empty($atts['class'])) {
            $atts['class'] = 'fc_input_control';
        } else {
            $atts['class'] .= ' fc_input_control';
        }

        $atts['value'] = $field['value'];

        return '<input ' . $this->buildAttributes($atts) . '/>';
    }

    public function renderDate($field)
    {
        // Note: combodate requires moment.js which is not available on public pages.
        // Use custom_date type (flatpickr) instead for new date fields.
        wp_enqueue_script('combodate', FLUENTCRM_PLUGIN_URL . 'assets/libs/combodate/combodate.js', ['jquery'], '1.0.7', true);

        add_action('wp_footer', function () use ($field) {
?>
            <script>
                jQuery(document).ready(function() {
                    jQuery('#<?php echo esc_attr($field['id']); ?>').combodate();
                });
            </script>
        <?php
        });
        return $this->renderInput($field);
    }

    public function renderDateDropdowns($field)
    {
        $id = esc_attr(Arr::get($field, 'id', 'fc_date'));
        $name = esc_attr(Arr::get($field, 'name', 'date'));
        $value = Arr::get($field, 'value', '');

        $selectedDay = '';
        $selectedMonth = '';
        $selectedYear = '';

        if ($value && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            $selectedYear  = (int) $m[1];
            $selectedMonth = (int) $m[2];
            $selectedDay   = (int) $m[3];
        }

        $currentYear = (int) gmdate('Y');
        $minYear = $currentYear - 120;

        // Day select (id for label for= so clicking label focuses first visible control)
        $html = '<div class="fc_date_dropdowns" id="' . $id . '_wrap">';
        $html .= '<select class="fc_date_dropdown fc_date_day" id="' . $id . '_day" data-role="day">';
        $html .= '<option value="">' . esc_html__('Day', 'fluent-crm') . '</option>';
        for ($d = 1; $d <= 31; $d++) {
            $sel = ($selectedDay === $d) ? ' selected' : '';
            $html .= '<option value="' . $d . '"' . $sel . '>' . sprintf('%02d', $d) . '</option>';
        }
        $html .= '</select>';

        // Month select
        $html .= '<select class="fc_date_dropdown fc_date_month" data-role="month">';
        $html .= '<option value="">' . esc_html__('Month', 'fluent-crm') . '</option>';
        for ($mo = 1; $mo <= 12; $mo++) {
            $sel = ($selectedMonth === $mo) ? ' selected' : '';
            $html .= '<option value="' . $mo . '"' . $sel . '>' . sprintf('%02d', $mo) . '</option>';
        }
        $html .= '</select>';

        // Year select
        $html .= '<select class="fc_date_dropdown fc_date_year" data-role="year">';
        $html .= '<option value="">' . esc_html__('Year', 'fluent-crm') . '</option>';
        for ($y = $currentYear; $y >= $minYear; $y--) {
            $sel = ($selectedYear === $y) ? ' selected' : '';
            $html .= '<option value="' . $y . '"' . $sel . '>' . $y . '</option>';
        }
        $html .= '</select>';

        $html .= '<input type="hidden" name="' . $name . '" id="' . $id . '" value="' . esc_attr($value) . '" />';
        $html .= '</div>';

        add_action('wp_footer', function () use ($id) {
        ?>
            <script>
                (function() {
                    var wrap = document.getElementById('<?php echo esc_js($id); ?>_wrap');
                    if (!wrap) return;
                    var hidden = document.getElementById('<?php echo esc_js($id); ?>');
                    var daySelect = wrap.querySelector('[data-role="day"]');
                    var monthSelect = wrap.querySelector('[data-role="month"]');
                    var yearSelect = wrap.querySelector('[data-role="year"]');
                    function daysInMonth(month, year) {
                        if (!month || !year) return 31;
                        var m = parseInt(month, 10);
                        var y = parseInt(year, 10);
                        return new Date(y, m, 0).getDate();
                    }
                    function updateDayOptions() {
                        var m = monthSelect.value;
                        var y = yearSelect.value;
                        var maxDay = daysInMonth(m, y);
                        var currentDay = parseInt(daySelect.value, 10) || 0;
                        var options = daySelect.querySelectorAll('option');
                        for (var i = 1; i < options.length; i++) {
                            var val = parseInt(options[i].value, 10);
                            options[i].disabled = val > maxDay;
                            if (val > maxDay && currentDay === val) currentDay = maxDay;
                        }
                        if (currentDay > maxDay) {
                            daySelect.value = String(maxDay);
                        }
                    }
                    function sync() {
                        var d = parseInt(daySelect.value, 10);
                        var m = parseInt(monthSelect.value, 10);
                        var y = parseInt(yearSelect.value, 10);
                        if (d && m && y) {
                            var maxDay = daysInMonth(m, y);
                            if (d > maxDay) d = maxDay;
                            hidden.value = y + '-' + (m < 10 ? '0' + m : m) + '-' + (d < 10 ? '0' + d : d);
                        } else {
                            hidden.value = '';
                        }
                    }
                    if (monthSelect) monthSelect.addEventListener('change', function() { updateDayOptions(); sync(); });
                    if (yearSelect) yearSelect.addEventListener('change', function() { updateDayOptions(); sync(); });
                    if (daySelect) daySelect.addEventListener('change', sync);
                    updateDayOptions();
                    sync();
                })();
            </script>
        <?php
        });

        return $html;
    }

    public function renderButton($field)
    {
        $containerClass = 'fc_field fc_field_btn';

        if ($givenClass = Arr::get($field, 'container_class')) {
            $containerClass .= ' ' . $givenClass;
        }

        $html = '<div class="' . esc_attr($containerClass) . '">';

        $label = Arr::get($field, 'btn_text');

        $html .= '<button ' . $this->buildAttributes(Arr::get($field, 'atts', [])) . '>' . wp_kses_post($label) . '</button>';

        $html .= '</div>';

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

    }

    private function buildAttributes($atts)
    {
        $items = [];

        $singleKeys = ['required', 'disabled', 'readonly', 'checked', 'selected', 'multiple'];

        foreach ($atts as $key => $value) {
            if ($value && in_array($key, $singleKeys)) {
                $items[] = $key;
                continue;
            }

            $items[] = esc_attr($key) . '="' . esc_html($value) . '"';
        }

        return implode(' ', $items);
    }

    private function renderTextarea($field)
    {
        $atts = $this->buildAttributes($field['atts']);
        $value = Arr::get($field, 'value', '');
        return '<textarea ' . $atts . '>' . esc_html($value) . '</textarea>';
    }
    public function renderMultiSelect($field)
    {
        $name = Arr::get($field, 'name');
        if (substr($name, -2) !== '[]') {
            $name .= '[]';
        }

        $atts = $this->buildAttributes([
            'id'               => Arr::get($field, 'id'),
            'name'             => $name,
            'multiple'         => true,
            'class'            => 'fc_input_control fc-js-choice-multi',
            'data-placeholder' => esc_attr(Arr::get($field, 'placeholder', __('Select options', 'fluent-crm'))),
        ]);

        $values = is_array($field['value']) ? $field['value'] : [];

        $html = '<select ' . $atts . '>';
        if ($placeholder = Arr::get($field, 'placeholder')) {
            $html .= '<option value="" disabled hidden>' . esc_html($placeholder) . '</option>';
        }
        foreach ($field['options'] as $key => $label) {
            $selected = in_array($key, $values) ? ' selected="selected"' : '';
            $html .= '<option value="' . esc_attr($key) . '" ' . $selected . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select>';

        return $html;
    }

    public function renderDateTimePicker($field)
    {

        // Build attributes for the input field
        $atts = $this->buildAttributes([
            'type'        => 'text',
            'id'          => Arr::get($field, 'id'),
            'name'        => Arr::get($field, 'name'),
            'value'       => esc_attr($field['value'] ?? ''),
            'class'       => 'fc_input_control fc-js-datetime-picker',
            'placeholder' => esc_attr(Arr::get($field, 'placeholder', 'Select date & time')),
        ]);

        // Return the input element
        return '<input ' . $atts . ' />';
    }
    public function renderDatePicker($field)
    {
        // Build attributes for the input field
        $atts = $this->buildAttributes([
            'type'        => 'text',
            'id'          => Arr::get($field, 'id'),
            'name'        => Arr::get($field, 'name'),
            'value'       => esc_attr($field['value'] ?? ''),
            'class'       => 'fc_input_control fc-js-date-picker',
            'placeholder' => esc_attr(Arr::get($field, 'placeholder', 'Select date')),
        ]);

        // Return the input element
        return '<input ' . $atts . ' />';
    }

    private function addExternalScriptsAndCss()
    {
        add_action('wp_footer', function () {
            $this->renderDatePickerScript();
            $this->renderDateTimePickerScript();
            $this->renderMultiSelectScript();
        });
        
        add_action('wp_enqueue_scripts', function () {
            $this->enqueueDatePickerAssets();
            $this->enqueueMultiSelectAssets();
        });
    }

    private function renderDatePickerScript()
    {
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const datePickers = document.querySelectorAll('.fc-js-date-picker');
                datePickers.forEach(picker => {
                    if (!picker.dataset.fpInitialized) {
                        flatpickr(picker, {
                            dateFormat: 'Y-m-d',
                            allowInput: true
                        });
                        picker.dataset.fpInitialized = true;
                    }
                });
            });
        </script>
    <?php
    }

    private function renderDateTimePickerScript()
    {
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const dateTimePickers = document.querySelectorAll('.fc-js-datetime-picker');
                dateTimePickers.forEach(picker => {
                    if (!picker.dataset.fpInitialized) {
                        flatpickr(picker, {
                            enableTime: true,
                            dateFormat: 'Y-m-d H:i:S',
                            time_24hr: true,
                            allowInput: true
                        });
                        picker.dataset.fpInitialized = true;
                    }
                });
            });
        </script>
    <?php
    }

    private function renderMultiSelectScript()
    {
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const selects = document.querySelectorAll('.fc-js-choice-multi');
                selects.forEach(select => {
                    if (!select.dataset.choicesInitialized) {
                        new Choices(select, {
                            removeItemButton: true,
                            placeholderValue: select.dataset.placeholder || '<?php echo esc_js(__('Select options', 'fluent-crm')); ?>',
                            searchEnabled: true,
                            itemSelectText: '',
                            noResultsText: '<?php echo esc_js(__('No matching options found', 'fluent-crm')); ?>',
                            noChoicesText: '<?php echo esc_js(__('No options available', 'fluent-crm')); ?>'
                        });
                        select.dataset.choicesInitialized = true;
                    }
                });
            });
        </script>
        <?php
    }

    private function enqueueDatePickerAssets()
    {
        wp_enqueue_style('flatpickr-css', FLUENTCRM_PLUGIN_URL . 'assets/libs/flatpickr/flatpickr.min.css', [], '4.6.13');
        wp_enqueue_script('flatpickr-js', FLUENTCRM_PLUGIN_URL . 'assets/libs/flatpickr/flatpickr.min.js', [], '4.6.13', true);
    }

    private function enqueueMultiSelectAssets()
    {
        wp_enqueue_style('choices-css', FLUENTCRM_PLUGIN_URL . 'assets/libs/choices/choices.min.css', [], '10.0.0');
        wp_enqueue_script('choices-js', FLUENTCRM_PLUGIN_URL . 'assets/libs/choices/choices.min.js', ['jquery'], '10.0.0', true);

        // Add custom CSS for dropdown positioning
        add_action('wp_head', function () {
        ?>
            <style>
                .fc_field_select-multi {
                    overflow: inherit !important;
                    z-index: 99999;
                }

                /* Position the choices container as relative */
                .choices {
                    position: relative !important;
                    overflow: inherit !important;
                }

                /* Make dropdown absolute so it doesn't take space */
                .choices__list.choices__list--dropdown {
                    position: absolute !important;
                    top: 100% !important;
                    left: 0 !important;
                    right: 0 !important;
                    z-index: 999999999999999999 !important;
                }
            </style>
<?php
        });
    }
}
