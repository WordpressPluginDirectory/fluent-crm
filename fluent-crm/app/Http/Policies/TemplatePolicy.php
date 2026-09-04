<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\Framework\Http\Request\Request;

/**
 *  TemplatePolicy - REST API Permission Policy
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */

class TemplatePolicy extends BasePolicy
{
    /**
     * Check user permission for any method
     * @param  \FluentCrm\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_email_templates');
    }

    /**
     * Allow email readers to browse template metadata without granting CRUD access.
     *
     * @param Request $request
     * @return bool
     */
    public function templates(Request $request)
    {
        return $this->currentUserCan('fcrm_read_emails') || $this->verifyRequest($request);
    }

    /**
     * Allow full detail reads only when editing or rendering an authorized preview.
     *
     * @param Request $request
     * @return bool
     */
    public function template(Request $request)
    {
        return $this->verifyRequest($request) || (
            $this->currentUserCan('fcrm_read_emails') &&
            $this->currentUserCan('fcrm_manage_emails')
        );
    }

    public function getBuiltInTemplate(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_email_templates');
    }

    // public function getDefaultCampaignTemplate(Request $request)
    // {
    //     return $this->currentUserCan('fcrm_read_emails') || $this->currentUserCan('fcrm_manage_email_templates');
    // }

    // public function setDefaultCampaignTemplate(Request $request)
    // {
    //     return $this->currentUserCan('fcrm_manage_email_templates');
    // }

    // public function deleteDefaultCampaignTemplate(Request $request)
    // {
    //     return $this->currentUserCan('fcrm_manage_email_templates');
    // }

    public function delete(Request $request)
    {
        return $this->templates($request) && $this->currentUserCan('fcrm_manage_email_delete');
    }

    public function handleBulkAction(Request $request)
    {
        return $this->delete($request);
    }
}
