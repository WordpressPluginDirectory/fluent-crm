<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\Framework\Http\Request\Request;

/**
 *  FunnelPolicy - REST API Permission Policy
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class FunnelPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if ($this->requestMethod($request) == 'GET') {
            return $this->currentUserCan('fcrm_read_funnels');
        }

        return $this->currentUserCan('fcrm_write_funnels');
    }

    public function delete(Request $request)
    {
        return $this->currentUserCan('fcrm_delete_funnels');
    }

    /**
     * Exporting a funnel retains the historical administrator-only boundary.
     *
     * @param Request $request
     * @return bool
     */
    public function exportFunnel(Request $request)
    {
        return $this->currentUserCan('manage_options');
    }

    public function handleBulkAction(Request $request)
    {
        // Match the controller's normalization before selecting a capability.
        $actionName = sanitize_text_field($request->get('action_name', ''));

        if ($actionName == 'delete_funnels') {
            return $this->currentUserCan('fcrm_delete_funnels');
        }

        return $this->currentUserCan('fcrm_write_funnels');
    }

    public function removeBulkSubscribers(Request $request)
    {
        return $this->currentUserCan('fcrm_delete_funnels');
    }

    public function deleteSubscribers(Request $request)
    {
        return $this->currentUserCan('fcrm_delete_funnels');
    }
}
