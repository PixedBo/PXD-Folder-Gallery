<?php

/**
 * @package     mod_folder_gallery
 * @copyright   (C) Pixed - pixed.it
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Pixed\Module\FolderGallery\Site\Dispatcher;

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * Dispatcher class for mod_folder_gallery.
 */
class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
    use HelperFactoryAwareTrait;

    /**
     * Returns the layout data passed to the template (tmpl/default.php or tmpl/uikit.php).
     *
     * Besides the scanned initial level, it builds the shared client config and resolves the
     * chosen layout framework once, so both templates render from a single source of truth.
     *
     * @return  array
     */
    protected function getLayoutData(): array
    {
        $data = parent::getLayoutData();

        /** @var \Pixed\Module\FolderGallery\Site\Helper\FolderGalleryHelper $helper */
        $helper = $this->getHelperFactory()->getHelper('FolderGalleryHelper');
        $params = $data['params'];

        // Only the first (top) level is scanned on render: cheap, regardless of archive size.
        $data['initial'] = $helper->getInitialLevel($params, $this->getApplication());

        $framework = $params->get('framework', 'default') === 'uikit' ? 'uikit' : 'default';
        $modId     = (int) $this->module->id;
        $cols      = (int) $params->get('columns', 4);

        $rootLabel = trim((string) $params->get('base_folder', 'images'), '/');
        $rootLabel = $rootLabel === '' ? Text::_('MOD_FOLDER_GALLERY_ROOT') : basename($rootLabel);

        $root = Uri::root(true);

        // Server-side view scalars used by the templates.
        $data['view'] = [
            'framework' => $framework,
            'modId'     => $modId,
            'elemId'    => 'folder-gallery-' . $modId,
            'cols'      => $cols,
            'rootLabel' => $rootLabel,
        ];

        // The per-instance options handed to the JS via Joomla.getOptions('mod_folder_gallery').
        $data['cfg'] = [
            'modid'     => $modId,
            'el'        => 'folder-gallery-' . $modId,
            'framework' => $framework,
            'cols'      => $cols,
            'ajaxUrl'   => $root . '/index.php?option=com_ajax&module=folder_gallery&format=json&method=browse',
            'listUrl'   => $root . '/index.php?option=com_ajax&module=folder_gallery&format=json&method=list',
            'rootLabel' => $rootLabel,
            'i18n'      => [
                'loadmore' => Text::_('MOD_FOLDER_GALLERY_LOAD_MORE'),
                'back'     => Text::_('MOD_FOLDER_GALLERY_BACK'),
                'empty'    => Text::_('MOD_FOLDER_GALLERY_EMPTY'),
                'error'    => Text::_('MOD_FOLDER_GALLERY_ERROR'),
                'loading'  => Text::_('MOD_FOLDER_GALLERY_LOADING'),
                'close'    => Text::_('MOD_FOLDER_GALLERY_CLOSE'),
                'prev'     => Text::_('MOD_FOLDER_GALLERY_PREV'),
                'next'     => Text::_('MOD_FOLDER_GALLERY_NEXT'),
            ],
        ];

        return $data;
    }
}
