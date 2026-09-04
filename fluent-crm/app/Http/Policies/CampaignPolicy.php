<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\Framework\Http\Request\Request;

/**
 *  CampaignPolicy - REST API Permission Policy
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class CampaignPolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        if ($this->requestMethod($request) == 'GET') {
            return $this->currentUserCan('fcrm_read_emails');
        }

        return $this->currentUserCan('fcrm_manage_emails');
    }

    public function delete(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_email_delete');
    }

    public function deleteCampaignEmails(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_email_delete');
    }

    /**
     * Authorize campaign bulk actions against their independent capabilities.
     *
     * @param Request $request
     * @return bool
     */
    public function handleBulkAction(Request $request)
    {
        $actionName = $request->getSafe('action_name', 'sanitize_text_field', '');

        if ($actionName === 'delete_campaigns') {
            return $this->currentUserCan('fcrm_manage_email_delete');
        }

        if ($actionName === 'apply_labels') {
            return $this->verifyRequest($request);
        }

        return false;
    }
}
