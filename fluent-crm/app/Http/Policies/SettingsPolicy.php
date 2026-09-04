<?php

namespace FluentCrm\App\Http\Policies;

use FluentCrm\App\Services\Helper;
use FluentCrm\Framework\Http\Request\Request;

/**
 * SettingsPolicy - REST API Permission Policy
 *
 * Settings routes are gated on `fcrm_manage_settings`, the highest FluentCRM
 * permission level, which is sufficient for the module on its own.
 *
 * A handful of routes reach beyond FluentCRM — installing a plugin, resetting the
 * database, minting REST credentials — and are additionally gated on a core
 * WordPress capability. Those checks already exist inside the controllers (for
 * the setup wizard, at the installer choke point `backgroundInstaller()` rather
 * than in the handler); the methods below declare the same requirement at the
 * policy layer so the gate is visible to `tests/lint/policy-coverage.php` and is
 * enforced before the request reaches the controller. The in-handler checks are
 * deliberately left in place: they still guard any non-REST caller, so this is
 * defence in depth rather than a relocation. Nobody who can call these routes
 * today loses access.
 */
class SettingsPolicy extends BasePolicy
{
    public function verifyRequest(Request $request)
    {
        return $this->currentUserCan('fcrm_manage_settings');
    }

    /**
     * Installing a companion plugin needs the WordPress capability for it, not just
     * CRM settings access. Mirrors SetupController's own `install_plugins` guard.
     *
     * @param Request $request
     * @return bool
     */
    protected function canInstallPlugins(Request $request)
    {
        return $this->verifyRequest($request) && current_user_can('install_plugins');
    }

    /**
     * Routes that reset or destroy plugin data, or mint REST credentials, stay
     * administrator-only. Mirrors SettingsController's own `manage_options` guard.
     *
     * @param Request $request
     * @return bool
     */
    protected function isAdministrator(Request $request)
    {
        return $this->verifyRequest($request) && current_user_can('manage_options');
    }

    public function handleFluentFormInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentSmtpInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentSupportInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentBoardsInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentCommunityInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentCartInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function handleFluentBookingInstall(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    /**
     * Finishing onboarding is a settings action, but installing companion plugins
     * from the same request also needs WordPress's plugin-install capability.
     *
     * The flags are read through the same Helper the controller uses. Reading them
     * any other way here would let the two disagree, and a payload the controller
     * honours but this gate does not is a straight bypass of `install_plugins`.
     *
     * @param Request $request
     * @return bool
     */
    public function CompleteWizard(Request $request)
    {
        $installFlags = Helper::getWizardPluginInstallFlags($request);

        if (in_array(true, $installFlags, true)) {
            return $this->canInstallPlugins($request);
        }

        return $this->verifyRequest($request);
    }

    /**
     * MCP adapter installation is a plugin install like the ones above.
     */
    public function installAdapter(Request $request)
    {
        return $this->canInstallPlugins($request);
    }

    public function resetDB(Request $request)
    {
        return $this->isAdministrator($request);
    }

    public function createRestKey(Request $request)
    {
        return $this->isAdministrator($request);
    }

    public function deleteRestKey(Request $request)
    {
        return $this->isAdministrator($request);
    }
}
