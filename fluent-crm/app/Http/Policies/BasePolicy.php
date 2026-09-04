<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\App\Services\PermissionManager;
use FluentCrm\Framework\Foundation\Policy;
use FluentCrm\Framework\Http\Request\Request;

/**
 *  BasePolicy - REST API Permission Policy
 *
 * @package FluentCrm\App\Http
 *
 * @version 1.0.0
 */
class BasePolicy extends Policy
{

    /**
     * Check user permission for any method
     * @param Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return $this->currentUserCan('manage_options');
    }

    public function currentUserCan($permission)
    {
        return PermissionManager::currentUserCan($permission);
    }

    /**
     * Get the routed REST method when WordPress has applied method override.
     *
     * @param Request $request
     * @return string
     */
    protected function requestMethod(Request $request)
    {
        if (fluentCrm()->bound('wprestrequest')) {
            $wpRestRequest = fluentCrm()->wprestrequest;

            if ($wpRestRequest instanceof \WP_REST_Request) {
                return $wpRestRequest->get_method();
            }
        }

        return $request->method();
    }
}
