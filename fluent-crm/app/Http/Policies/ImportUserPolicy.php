<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\Framework\Http\Request\Request;

/**
 *  ImportUserPolicy - Import Contact Policy
 *
 * @package FluentCrm\App\Http
 */
class ImportUserPolicy extends BasePolicy {
    /**
     * Check user permission for any method
     *
     * @param \FluentCrm\Framework\Http\Request\Request $request
     *
     * @return Boolean
     */
    public function verifyRequest( Request $request )
    {
        return $this->currentUserCan('fcrm_manage_contacts');
    }

    /**
     * WordPress-user imports expose user records, so they require both CRM
     * contact management and WordPress user-list access.
     */
    public function importUsers(Request $request)
    {
        return $this->verifyRequest($request) && current_user_can('list_users');
    }

    /**
     * Apply the WordPress-user boundary only to the built-in users driver.
     *
     * `driver` is a URL path segment, not a query/body field, so it never
     * reaches the Request object. The router substitutes route parameters by
     * name into the policy call the same way it does for the controller
     * action, so it must be declared in the signature to be seen at all.
     */
    public function getDriver(Request $request, $driver = null)
    {
        if ($driver === 'users') {
            return $this->importUsers($request);
        }

        return $this->verifyRequest($request);
    }

    /**
     * Use the same driver-specific permission for import execution.
     */
    public function importData(Request $request, $driver = null)
    {
        return $this->getDriver($request, $driver);
    }
}
