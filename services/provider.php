<?php

/**
 * @package     mod_folder_gallery
 * @copyright   (C) Pixed - pixed.it
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\Service\Provider\HelperFactory;
use Joomla\CMS\Extension\Service\Provider\Module;
use Joomla\CMS\Extension\Service\Provider\ModuleDispatcherFactory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new ModuleDispatcherFactory('\\Pixed\\Module\\FolderGallery'));
        $container->registerServiceProvider(new HelperFactory('\\Pixed\\Module\\FolderGallery\\Site\\Helper'));
        $container->registerServiceProvider(new Module());
    }
};
