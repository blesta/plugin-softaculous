<?php
class SoftaculousPlugin extends Plugin
{
    public function __construct()
    {
        Language::loadLang('softaculous', null, dirname(__FILE__) . DS . 'language' . DS);
        Loader::loadComponents($this, ['Input']);

        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    public function install($plugin_id)
    {
        return $plugin_id;
    }

    public function getEvents()
    {
        return [
            [
                'event' => 'Services.add',
                'callback' => ['this', 'softAutoInstall']
            ],
            [
                'event' => 'Services.edit',
                'callback' => ['this', 'softAutoInstall']
            ]
            // Add multiple events here
        ];
    }

    public function softAutoInstall($event)
    {
        if (!isset($this->ModuleManager)) {
            Loader::loadModels($this, ['ModuleManager']);
        }
        if (!isset($this->Services)) {
            Loader::loadModels($this, ['Services']);
        }

        $par = $event->getParams();

        // Get module info
        $module_info = $this->getModuleClassByPricingId($par['vars']['pricing_id']);

        // Make sure the service is being activated at this time
        $service_activated = isset($par['vars']['status'])
            && $par['vars']['status'] == 'active'
            && ($event->getName() == 'Services.add'
                || ($event->getName() == 'Services.edit'
                    && in_array($par['old_service']->status, ['pending', 'in_review'])
                )
            );

        // This plugin only supports the follwing modules: cPanel and CentOS Web Panel
        $accepted_modules = ['cpanel', 'centoswebpanel'];
        if ($service_activated && $module_info && in_array($module_info->class, $accepted_modules)) {
            // Fetch necessary data
            $service = $this->Services->get($par['service_id']);
            $module_row = $this->ModuleManager->getRow($service->module_row_id);
            try {
                $installer = $this->loadInstaller($module_info->class);

                $installer->install($service, $module_row->meta);
            } catch (Throwable $e) {
                throw new Exception('Unable to load library');
            } catch (Exception $e) {
                throw new Exception('Unable to load library');
            }
        }
    }

    /**
     * Returns info regarding the module belonging to the given $package_pricing_id
     *
     * @param int $package_pricing_id The package pricing ID to fetch the module of
     * @return mixed A stdClass object containing module info and the package
     *  ID belonging to the given $package_pricing_id, false if no such module exists
     */
    private function getModuleClassByPricingId($package_pricing_id)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        return $this->Record->select(['modules.*', 'packages.id' => 'package_id'])->from('package_pricing')->
            innerJoin('packages', 'packages.id', '=', 'package_pricing.package_id', false)->
            innerJoin('modules', 'modules.id', '=', 'packages.module_id', false)->
            where('package_pricing.id', '=', $package_pricing_id)->
            fetch();
    }

	/**
	 * Loads the given library into this object
	 *
	 * @param string $panel_name The panel to load an installer for
     * @return SoftaculousInstaller
	 */
	private function loadInstaller($panel_name) {
        $class_name = ucwords($panel_name) . 'Installer';
        if (isset($this->{$class_name})) {
            return $this->{$class_name};
        }

		$file_name = dirname(__FILE__) . DS . 'lib' . DS . $panel_name . '_installer.php';

		// Load the library requested
		include_once $file_name;

        $this->{$class_name} = new $class_name();
        return $this->{$class_name};
	}
}
