<?php

namespace FluentCrm\App\Http\Controllers;


use FluentCrm\App\Models\Funnel;
use FluentCrm\Framework\Request\Request;

/**
 *  FunnelLabelController - REST API Handler Class
 *
 *  REST API Handler
 *
 * @package FluentCrm\App\Http
 *
 * @version 2.9.25
 */
class FunnelLabelController extends Controller
{
    public function labels()
    {
        $labels = fluentcrm_get_option('funnel_custom_labels');

        $formattedLabels = [];
        if (empty($labels)) {
            return [
                'labels' => $formattedLabels
            ];
        }

        foreach ($labels as $label) {
            $formattedLabels[] = [
                'name'  => $label['name'],
                'slug'  => $label['slug'],
                'color' => $label['color']
            ];
        }

        return [
            'labels' => $formattedLabels
        ];
    }

    public function createLabel(Request $request)
    {
        $label = $request->get('label');
        // Sanitize each field within the associative array
        $sanitizedLabels = [
            'slug'  => isset($label['slug']) ? sanitize_text_field($label['slug']) : '',
            'name'  => isset($label['name']) ? sanitize_text_field($label['name']) : '',
            'color' => isset($label['color']) ? sanitize_text_field($label['color']) : ''
        ];
        $oldLabels = fluentcrm_get_option('funnel_custom_labels', []);

        if (array_key_exists($label['slug'], $oldLabels)) {
            $oldLabels[$label['slug']] = array_merge($oldLabels[$label['slug']], $sanitizedLabels);
        } else {
            $oldLabels[$label['slug']] = $sanitizedLabels;
        }

        fluentcrm_update_option('funnel_custom_labels', $oldLabels);

        return [
            'labels' => $oldLabels,
            'message' => __('Labels has been Updated successfully', 'fluent-crm')
        ];
    }

    public function deleteLabel(Request $request)
    {
        $funnelId  = $request->getSafe('funnel_id', 'intval');
        $labelSlug = $request->getSafe('label_slug');
        $action    = $request->getSafe('action');

        if (!$labelSlug) {
            return [
                'message' => __('Please provide label slug', 'fluent-crm')
            ];
        }

        switch ($action) {
            case 'delete_from_funnel':
                $this->deleteLabelFromFunnel($funnelId, $labelSlug);
                return [
                    'message' => __('Labels has been deleted successfully', 'fluent-crm')
                ];
            case 'delete_from_funnel_label':
                $this->deleteLabelFromFunnelLabel($labelSlug);
                return [
                    'message' => __('Label has been deleted successfully', 'fluent-crm')
                ];
            default:
                return [
                    'message' => __('Invalid Action', 'fluent-crm')
                ];
        }
    }

    protected function deleteLabelFromFunnel($funnelId, $slug)
    {
        $funnel = Funnel::findOrFail($funnelId);
        if (!$funnel) {
            return [
                'message' => __('Label not found', 'fluent-crm')
            ];
        }

        $labelMeta = $funnel->getLabelMeta();
        if (!$labelMeta) {
            return [
                'message' => __('Label not found', 'fluent-crm')
            ];
        }
        $updatedLabels = array_diff($labelMeta->value, [$slug]);
        $funnel->updateOrDeleteLabel($updatedLabels);
    }

    protected function deleteLabelFromFunnelLabel($slug)
    {
        $customLabels = fluentcrm_get_option('funnel_custom_labels', []);
        if (!array_key_exists($slug, $customLabels)) {
            return [
                'message' => __('Label not found', 'fluent-crm')
            ];
        }

        unset($customLabels[$slug]);
        fluentcrm_update_option('funnel_custom_labels', $customLabels);

        Funnel::removeLabelFromAllFunnels($slug);
    }
}