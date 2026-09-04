<?php

namespace FluentCrm\App\Services\ExternalIntegrations\FluentCart\Actions;

use FluentCart\Api\Resource\CouponResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Coupon;
use FluentCrm\App\Models\FunnelMetric;
use FluentCrm\App\Models\Subscriber;
use FluentCrm\Framework\Support\Arr;

/**
 * Lazily materialises the real FluentCart coupon for a generated dynamic code.
 *
 * The CreateCouponAction only stores a unique code on the funnel metric. This service turns
 * that code into an actual fct_coupons row the first time the {{cart_coupon.*}} smartcode is
 * parsed, reading the originating sequence's settings (or a base coupon when in template mode)
 * and translating FluentCRM's Woo-style UI settings into FluentCart's coupon shape.
 */
class DynamicCouponService
{
    /**
     * Create the FluentCart coupon for $code if it does not already exist (idempotent).
     *
     * Returns false whenever $code is NOT backed by a real fct_coupons row, so the caller can
     * avoid shipping a code FluentCart would reject at checkout. Because creation happens at
     * email-parse time rather than at checkout, a silent failure here would otherwise put a dead
     * coupon straight into the customer's inbox.
     *
     * @return bool True when a coupon row for $code exists after this call.
     */
    public function ensureCouponExists($code, FunnelMetric $funnelMetric, Subscriber $subscriber)
    {
        $code = $this->sanitizeCouponCode($code);

        if (!$code) {
            return false;
        }

        if (Coupon::query()->where('code', $code)->first()) {
            return true;
        }

        $sequence = $funnelMetric->sequence;
        if (!$sequence || $sequence->action_name !== 'fcrm_create_fluentcart_coupon') {
            return false;
        }

        $couponData = $this->getFormattedCouponData(Arr::get($sequence->settings, 'code_settings', []), $subscriber);
        if (!$couponData) {
            return false;
        }

        $couponData['code'] = $code;
        $couponData['title'] = sanitize_text_field(sprintf(__('FluentCRM Automation Coupon - %s', 'fluent-crm'), $code));
        $couponData['notes'] = sanitize_textarea_field(sprintf(
            __('Created dynamically from FluentCRM Automation Funnel: %s', 'fluent-crm'),
            $funnelMetric->funnel ? $funnelMetric->funnel->title : ''
        ));

        // This runs inside the email sending path, which is NOT covered by FunnelProcessor's
        // action try/catch. fct_coupons.code is UNIQUE, so a racing insert of the same code
        // throws; that must not take the whole sending job down. Re-check on failure because
        // the racing writer may have created the very row we wanted.
        try {
            $created = CouponResource::create($couponData);
        } catch (\Throwable $e) {
            return (bool) Coupon::query()->where('code', $code)->first();
        }

        $createdId = Arr::get($created, 'data.id');

        if (!$createdId) {
            return false;
        }

        $coupon = Coupon::query()->find($createdId);
        if ($coupon) {
            $coupon->updateMeta('_fc_coupon', $funnelMetric->id);
        }

        do_action('fluent_crm/fluentcart_dynamic_coupon_created', $coupon, $funnelMetric, $subscriber, $couponData);

        return true;
    }

    /**
     * Translate stored action settings into a CouponResource::create() payload.
     *
     * Amount/condition units: CouponResource::formatAmount() cent-converts `amount`
     * (non-percentage), `conditions.min_purchase_amount` and `conditions.max_discount_amount`,
     * but NOT `conditions.max_purchase_amount` — FluentCart stores that bound in major units,
     * so it is passed through as a plain decimal.
     *
     * @return array|false
     */
    public function getFormattedCouponData($data, Subscriber $subscriber)
    {
        if (Arr::get($data, 'template_type') === 'templated') {
            $data = $this->mergeBaseCouponData($data);
        }

        if (!$data) {
            return false;
        }

        $type = $this->mapDiscountType(Arr::get($data, 'discount_type', 'percentage'));
        $amount = $this->sanitizeAmount(Arr::get($data, 'amount', 0));

        if ($type === 'percentage') {
            $amount = min(100, $amount);
        }

        $conditions = [
            'min_purchase_amount' => $this->sanitizeAmount(Arr::get($data, 'minimum_amount', 0)),
            // NOT cent-converted: FluentCart stores max_purchase_amount in major units. DiscountService
            // compares it directly against the already-divided cart total, while min_purchase_amount is
            // divided by 100 first. formatAmount() leaves this key alone for exactly that reason.
            'max_purchase_amount' => $this->sanitizeAmount(Arr::get($data, 'maximum_amount', 0)),
            'max_discount_amount' => $this->sanitizeAmount(Arr::get($data, 'max_discount_amount', 0)),
            'apply_to_whole_cart' => 'yes',
            'apply_to_quantity'   => 'no',
            'max_uses'            => $this->sanitizeInt(Arr::get($data, 'usage_limit', 0)),
            'max_per_customer'    => $this->sanitizeInt(Arr::get($data, 'usage_limit_per_user', 0)),
            'included_categories' => $this->sanitizeIds(Arr::get($data, 'product_categories', [])),
            'excluded_categories' => $this->sanitizeIds(Arr::get($data, 'exclude_product_categories', [])),
            'included_products'   => $this->sanitizeIds(Arr::get($data, 'product_ids', [])),
            'excluded_products'   => $this->sanitizeIds(Arr::get($data, 'exclude_product_ids', [])),
            'email_restrictions'  => Arr::get($data, 'contact_email_only') === 'yes' ? sanitize_email($subscriber->email) : '',
            'is_recurring'        => 'no',
        ];

        return [
            'priority'         => $this->sanitizeInt(Arr::get($data, 'priority', 0)),
            'type'             => $type,
            'conditions'       => $conditions,
            'amount'           => $amount,
            'use_count'        => 0,
            'status'           => 'active',
            'stackable'        => Arr::get($data, 'individual_use') === 'yes' ? 'no' : 'yes',
            'show_on_checkout' => 'no',
            'start_date'       => null,
            'end_date'         => $this->getExpiryDate($data),
        ];
    }

    /**
     * Hydrate the settings array from an existing FluentCart coupon used as a template.
     *
     * FluentCart stores fixed amounts and min_purchase_amount in cents, so those are converted
     * back to plain decimals (via toDecimalWithoutComma, NOT toDecimal which returns a currency
     * formatted string) before they re-enter the normal cents conversion in getFormattedCouponData.
     * max_purchase_amount is the exception: it is stored in major units and copied verbatim.
     *
     * @return array|false
     */
    private function mergeBaseCouponData($data)
    {
        $existingCoupon = intval(Arr::get($data, 'base_coupon_id'));
        if (!$existingCoupon) {
            return false;
        }

        $coupon = Coupon::query()->find($existingCoupon);
        if (!$coupon) {
            return false;
        }

        $conditions = $coupon->conditions ?: [];

        // Percentage amounts are stored verbatim (e.g. 10 = 10%); fixed amounts are stored in cents.
        $data['amount'] = $coupon->type === 'percentage' ? $coupon->amount : Helper::toDecimalWithoutComma($coupon->amount);
        $data['discount_type'] = $coupon->type === 'percentage' ? 'percentage' : 'fixed';
        $data['product_ids'] = Arr::get($conditions, 'included_products', []);
        $data['exclude_product_ids'] = Arr::get($conditions, 'excluded_products', []);
        $data['product_categories'] = Arr::get($conditions, 'included_categories', []);
        $data['exclude_product_categories'] = Arr::get($conditions, 'excluded_categories', []);
        $data['minimum_amount'] = Helper::toDecimalWithoutComma(Arr::get($conditions, 'min_purchase_amount', 0));
        // max_purchase_amount is already stored in major units, so it needs no conversion in
        // either direction — getFormattedCouponData() passes it straight back through.
        $data['maximum_amount'] = Arr::get($conditions, 'max_purchase_amount', 0);
        $data['usage_limit'] = Arr::get($conditions, 'max_uses', 0);
        $data['usage_limit_per_user'] = Arr::get($conditions, 'max_per_customer', 0);
        $data['individual_use'] = $coupon->stackable === 'yes' ? 'no' : 'yes';

        if ($coupon->end_date) {
            $data['expiry_type'] = 'fixed';
            $data['date_expires'] = $coupon->end_date;
        }

        return $data;
    }

    /**
     * Map the Woo-style UI discount value to FluentCart's coupon type.
     */
    private function mapDiscountType($discountType)
    {
        return in_array($discountType, ['percent', 'percentage'], true) ? 'percentage' : 'fixed';
    }

    /**
     * Resolve the coupon end date from the never|fixed|relative_days expiry settings.
     */
    private function getExpiryDate($data)
    {
        $expiryType = sanitize_text_field(Arr::get($data, 'expiry_type', 'never'));

        if ($expiryType === 'fixed') {
            return $this->sanitizeDate(Arr::get($data, 'date_expires'));
        }

        if ($expiryType === 'relative_days') {
            $days = $this->sanitizeInt(Arr::get($data, 'expiry_days', 0));
            return $days > 0 ? gmdate('Y-m-d H:i:s', strtotime('+' . $days . ' days')) : null;
        }

        return null;
    }

    /**
     * Keep generated coupon codes inside FluentCart's validation contract.
     */
    private function sanitizeCouponCode($code)
    {
        return substr(sanitize_text_field((string) $code), 0, 50);
    }

    private function sanitizeAmount($amount)
    {
        return max(0, floatval($amount));
    }

    private function sanitizeInt($value)
    {
        return max(0, intval($value));
    }

    private function sanitizeIds($ids)
    {
        return array_values(array_filter(array_map('intval', (array) $ids)));
    }

    private function sanitizeDate($date)
    {
        $date = sanitize_text_field((string) $date);
        $timestamp = $date ? strtotime($date) : false;

        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
    }
}
