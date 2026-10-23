<?php

/**
 * @package     mod_folder_gallery
 * @copyright   (C) Pixed - pixed.it
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * @var array                     $initial  Data for the top level.
 * @var array                     $view     Server-side view scalars (framework, modId, cols, ...).
 * @var array                     $cfg      Per-instance options passed to the JS.
 * @var \Joomla\Registry\Registry $params   Module parameters.
 * @var object                    $module   Module instance.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$app = Factory::getApplication();
$doc = $app->getDocument();
$wa  = $doc->getWebAssetManager();

// UIkit layout is rendered by a dedicated template (shares the same JS + script options).
if (($view['framework'] ?? 'default') === 'uikit') {
    require __DIR__ . '/uikit.php';

    return;
}

$wa->registerAndUseStyle('mod_folder_gallery', 'mod_folder_gallery/folder_gallery.css');
$wa->registerAndUseScript('mod_folder_gallery', 'mod_folder_gallery/folder_gallery.js', [], ['defer' => true]);

$doc->addScriptOptions('mod_folder_gallery', [(string) $view['modId'] => $cfg]);

$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$elemId    = $view['elemId'];
$modId     = $view['modId'];
$cols      = $view['cols'];
$rootLabel = $view['rootLabel'];

// Inline folder icon (kept identical to the JS render).
$folderIcon = '<svg viewBox="0 0 24 24" width="32" height="32" aria-hidden="true" focusable="false">'
    . '<path fill="currentColor" d="M10 4H2v16h20V6H12l-2-2z"/></svg>';
?>
<div id="<?php echo $esc($elemId); ?>" class="folder-gallery" data-fg-modid="<?php echo $modId; ?>" style="--fg-cols: <?php echo $cols; ?>;">

	<nav class="fg-breadcrumb" aria-label="<?php echo $esc(Text::_('MOD_FOLDER_GALLERY_BREADCRUMB')); ?>">
		<button type="button" class="fg-crumb" data-path=""><?php echo $esc($rootLabel); ?></button>
	</nav>

	<div class="fg-body">
		<?php if (!empty($initial['error'])) : ?>
			<p class="fg-message fg-message--error"><?php echo $esc($initial['error']); ?></p>
		<?php else : ?>

			<?php if (!empty($initial['folders'])) : ?>
				<div class="fg-folders">
					<?php foreach ($initial['folders'] as $folder) : ?>
						<button type="button" class="fg-folder" data-path="<?php echo $esc($folder['path']); ?>">
							<span class="fg-folder-icon"><?php echo $folderIcon; ?></span>
							<span class="fg-folder-name"><?php echo $esc($folder['name']); ?></span>
							<?php if ($folder['count'] !== null) : ?>
								<span class="fg-folder-count"><?php echo (int) $folder['count']; ?></span>
							<?php endif; ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if (!empty($initial['images'])) : ?>
				<div class="fg-grid">
					<?php foreach ($initial['images'] as $i => $image) : ?>
						<button type="button" class="fg-thumb" data-index="<?php echo (int) $i; ?>"
								data-full="<?php echo $esc($image['full']); ?>"
								aria-label="<?php echo $esc($image['name']); ?>">
							<img src="<?php echo $esc($image['thumb']); ?>" alt="<?php echo $esc($image['name']); ?>"
								loading="lazy" decoding="async">
						</button>
					<?php endforeach; ?>
				</div>

				<?php if ($initial['pages'] > 1) : ?>
					<div class="fg-loadmore-wrap">
						<button type="button" class="fg-loadmore" data-page="1" data-pages="<?php echo (int) $initial['pages']; ?>">
							<?php echo $esc(Text::_('MOD_FOLDER_GALLERY_LOAD_MORE')); ?>
						</button>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if (empty($initial['folders']) && empty($initial['images'])) : ?>
				<p class="fg-message"><?php echo $esc(Text::_('MOD_FOLDER_GALLERY_EMPTY')); ?></p>
			<?php endif; ?>

		<?php endif; ?>
	</div>
</div>
