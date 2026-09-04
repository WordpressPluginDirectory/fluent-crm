<?php

namespace FluentCrm\App\Services\ExternalIntegrations\FluentCart\Actions;

use FluentCart\App\Models\Coupon;
use FluentCrm\App\Models\FunnelMetric;
use FluentCrm\App\Services\Funnel\BaseAction;
use FluentCrm\Framework\Support\Arr;

/**
 * Free FluentCart automation action: generate a unique per-contact coupon code.
 *
 * Mirrors the WooCommerce Pro "Create Coupon" action. On execution it does NOT create a
 * FluentCart coupon record; it only generates a unique code and stores it on the funnel
 * metric. The real coupon is created lazily by DynamicCouponService when the dynamic
 * {{cart_coupon.*}} smartcode is parsed (FluentCart exposes no checkout-time coupon
 * resolution hook, so parse-time creation is the pragmatic equivalent).
 */
class CreateCouponAction extends BaseAction
{
    public function __construct()
    {
        $this->actionName = 'fcrm_create_fluentcart_coupon';
        $this->priority = 99;
        parent::__construct();

        add_filter('fluentcrm_funnel_sequence_saving_' . $this->actionName, [$this, 'validateSequenceSettings'], 10, 2);
    }

    public function getBlock()
    {
        return [
            'category'    => __('FluentCart', 'fluent-crm'),
            'title'       => __('Create Coupon', 'fluent-crm'),
            'description' => __('Create FluentCart Coupon Code', 'fluent-crm'),
            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="24px" height="24px" viewBox="0 0 24 24" version="1.1"><g id="surface1"><path style=" stroke:none;fill-rule:nonzero;fill:rgb(100%,100%,100%);fill-opacity:1;" d="M 2.398438 0 L 21.601562 0 C 22.925781 0 24 1.074219 24 2.398438 L 24 21.601562 C 24 22.925781 22.925781 24 21.601562 24 L 2.398438 24 C 1.074219 24 0 22.925781 0 21.601562 L 0 2.398438 C 0 1.074219 1.074219 0 2.398438 0 Z M 2.398438 0 "/><path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,62.352943%);fill-opacity:1;" d="M 10.925781 16.476562 L 3.769531 16.476562 L 4.894531 13.878906 C 5.222656 13.117188 5.972656 12.625 6.804688 12.625 L 15.328125 12.625 L 14.746094 13.964844 C 14.085938 15.488281 12.585938 16.476562 10.925781 16.476562 Z M 10.925781 16.476562 "/><path style=" stroke:none;fill-rule:nonzero;fill:rgb(0%,0%,62.352943%);fill-opacity:1;" d="M 16.851562 11.394531 L 6.789062 11.394531 L 7.367188 10.054688 C 8.027344 8.53125 9.53125 7.542969 11.191406 7.542969 L 19.886719 7.542969 L 18.761719 10.140625 C 18.433594 10.902344 17.683594 11.394531 16.851562 11.394531 Z M 16.851562 11.394531 "/></g></svg>',
            'settings'    => [
                'code_settings' => [
                    'base_coupon_id'             => '',
                    'template_type'              => 'new',
                    'smart_code'                 => '',
                    'code_type'                  => 'masked',
                    'code_prefix'                => '',
                    'amount'                     => 0,
                    // UI value-space (shared _AdvancedCouponSettings.vue el-select): percent | fixed_cart | fixed_product.
                    // Must be 'percent' (NOT 'percentage') so the dropdown shows a selection and the % suffix renders.
                    // DynamicCouponService::mapDiscountType() converts this to FluentCart's percentage|fixed at write time.
                    'discount_type'              => 'percent',
                    'product_ids'                => [],
                    'exclude_product_ids'        => [],
                    'product_categories'         => [],
                    'exclude_product_categories' => [],
                    'minimum_amount'             => '',
                    'maximum_amount'             => '',
                    'expiry_type'                => 'never', // never | fixed | relative_days
                    'expiry_days'                => '',
                    'date_expires'               => '',
                    'individual_use'             => 'no',
                    'exclude_sale_items'         => 'no', // kept for shared-UI parity; not written to FluentCart.
                    'contact_email_only'         => 'no',
                    'limit_usage_to_x_items'     => '', // kept for shared-UI parity; not written to FluentCart.
                    'usage_limit'                => '',
                    'usage_limit_per_user'       => '',
                ],
            ],
        ];
    }

    public function getBlockFields()
    {
        return [
            'title'     => __('Create Coupon', 'fluent-crm'),
            'sub_title' => __('Create FluentCart Coupon Code', 'fluent-crm'),
            'fields'    => [
                'code_settings' => [
                    'type'                => 'advanced_coupon_settings',
                    'label'               => '',
                    // Point each ajax selector in the shared UI at FluentCart's option providers
                    // instead of the Woo defaults (woo_coupons / woo_products / woo_categories).
                    'option_key'          => 'fluent_cart_coupons',
                    // Product restrictions must store VARIATION ids: FluentCart matches coupon
                    // included_products / excluded_products against the cart item's object_id, which
                    // is the product variation id (not the product post id). See CartHelper.
                    'product_option_key'  => 'fluent_cart_product_variations',
                    'category_option_key' => 'fluent_cart_product_categories',
                    // Show FluentCart's store currency symbol on the amount/min/max inputs so the
                    // UI is correct even when WooCommerce (which sets woo_currency_sign) is inactive.
                    'currency_sign'       => $this->getCurrencySign(),
                    // FluentCart's coupon editor supports fixed and percentage discounts only.
                    'supports_free_shipping' => false,
                ],
            ],
        ];
    }

    /**
     * Generate the unique per-contact code and persist it on the funnel metric.
     *
     * @param \FluentCrm\App\Models\Subscriber   $subscriber
     * @param \FluentCrm\App\Models\FunnelSequence $sequence
     * @param int                                $funnelSubscriberId
     * @param \FluentCrm\App\Models\FunnelMetric $funnelMetric
     * @return bool
     */
    public function handle($subscriber, $sequence, $funnelSubscriberId, $funnelMetric)
    {
        $settings = $sequence->settings;
        $funnelMetric->notes = $this->getFreshCoupon($settings['code_settings']['code_prefix'] ?? '', $subscriber);
        $funnelMetric->save();

        return true;
    }

    /**
     * Reject templated coupon settings before they are persisted without a base coupon.
     */
    public function validateSequenceSettings($sequence)
    {
        $codeSettings = Arr::get($sequence, 'settings.code_settings', []);

        if (Arr::get($codeSettings, 'template_type') === 'templated' && !intval(Arr::get($codeSettings, 'base_coupon_id'))) {
            throw new \Exception(esc_html__('Please select an existing coupon code', 'fluent-crm'));
        }

        return $sequence;
    }

    /**
     * Build a collision-free coupon code, optionally personalising the prefix with smartcodes.
     *
     * The subscriber is only passed on the first attempt so the smartcode-parsed prefix is not
     * re-parsed on retry; retries just regenerate the random suffix.
     */
    private function getFreshCoupon($codePrefix, $subscriber = null)
    {
        if (!$codePrefix) {
            $codePrefix = $this->randomString(5);
        }

        if ($subscriber) {
            $codePrefix = (string) apply_filters('fluent_crm/parse_campaign_email_text', $codePrefix, $subscriber);
        }

        // Cap at 43 chars so the full code (prefix + '-' + 6-char suffix) stays within FluentCart's 50-character coupon code limit.
        $codePrefix = substr(sanitize_text_field($codePrefix), 0, 43);
        $randomSuffix = $this->randomString(6);
        $code = $codePrefix . '-' . $randomSuffix;

        // Also check staged (unmaterialised) codes in funnel metrics so two contacts
        // cannot receive the same generated code before either coupon is created.
        $existInCoupon = Coupon::query()->where('code', $code)->first();
        $existInMetric = FunnelMetric::where('notes', $code)->first();

        if ($existInCoupon || $existInMetric) {
            return $this->getFreshCoupon($codePrefix, null);
        }

        return $code;
    }

    /**
     * Return a cryptographically secure random string of $length characters from the alphanumeric alphabet.
     */
    private function randomString($length)
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max = strlen($alphabet) - 1;
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }
        return $result;
    }

    /**
     * Resolve FluentCart's configured store currency symbol for the coupon amount inputs.
     *
     * Guarded so a missing/partial FluentCart config never breaks the funnel block fields request.
     */
    private function getCurrencySign()
    {
        try {
            return (string) \FluentCart\App\Helpers\Helper::shopConfig('currency_sign');
        } catch (\Exception $e) {
            return '';
        }
    }
}
