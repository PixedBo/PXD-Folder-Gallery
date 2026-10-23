<?php

/**
 * @package     mod_folder_gallery
 * @copyright   (C) Pixed - pixed.it
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * UIkit 3 layout. Rendered (via tmpl/default.php) when the module's "Layout style" is set to
 * UIkit. It uses the theme's UIkit classes + lightbox (YOOtheme / UIkit sites), so nothing but
 * a tiny bridge CSS is loaded. The hand-rolled JS lightbox is used only as a fallback when the
 * global UIkit object is not available on the page.
 *
 * @var array   $initial  Data for the top level.
 * @var array   $view     Server-side view scalars.
 * @var array   $cfg      Per-instance options passed to the JS.
 * @var object  $doc      Document.
 * @var object  $wa       Web asset manager.
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

// Same navigation core as the default skin; UIkit itself is provided by the theme (not enqueued here).
$wa->registerAndUseScript('mod_folder_gallery', 'mod_folder_gallery/folder_gallery.js', [], ['defer' => true]);
$wa->registerAndUseStyle('mod_folder_gallery_uikit', 'mod_folder_gallery/folder_gallery.uikit.css');

$doc->addScriptOptions('mod_folder_gallery', [(string) $view['modId'] => $cfg]);

$esc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$elemId    = $view['elemId'];
$modId     = $view['modId'];
$cols      = (int) $view['cols'];
$rootLabel = $view['rootLabel'];

// Maps the columns option to UIkit responsive child-width classes (2-up on phones, N-up from medium).
$childWidth = 'uk-grid-small uk-child-width-1-2 uk-child-width-1-' . $cols . '@m';

// Inline folder icon (kept identical to the JS render).
$folderIcon = '<svg viewBox="0 0 24 24" width="32" height="32" aria-hidden="true" focusable="false">'
    . '<path fill="currentColor" d="M10 4H2v16h20V6H12l-2-2z"/></svg>';
?>
<div id="<?php echo $esc($elemId); ?>" class="folder-gallery folder-gallery--uikit" data-fg-modid="<?php echo $modId; ?>">

	<nav class="fg-breadcrumb" aria-label="<?php echo $esc(Text::_('MOD_FOLDER_GALLERY_BREADCRUMB')); ?>">
		<ul class="uk-breadcrumb">
			<li><span><?php echo $esc($rootLabel); ?></span></li>
		</ul>
	</nav>

	<div class="fg-body">
		<?php if (!empty($initial['error'])) : ?>
			<p class="fg-message fg-message--error uk-text-danger"><?php echo $esc($initial['error']); ?></p>
		<?php else : ?>

			<?php if (!empty($initial['folders'])) : ?>
				<div class="fg-folders <?php echo $childWidth; ?>" uk-grid>
					<?php foreach ($initial['folders'] as $folder) : ?>
						<div>
							<button type="button" class="fg-folder uk-button uk-button-default" data-path="<?php echo $esc($folder['path']); ?>">
								<span class="fg-folder-icon"><?php echo $folderIcon; ?></span>
								<span class="fg-folder-name"><?php echo $esc($folder['name']); ?></span>
								<?php if ($folder['count'] !== null) : ?>
									<span class="fg-folder-count"><?php echo (int) $folder['count']; ?></span>
								<?php endif; ?>
							</button>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if (!empty($initial['images'])) : ?>
				<div class="fg-grid <?php echo $childWidth; ?>" uk-grid>
					<?php foreach ($initial['images'] as $i => $image) : ?>
						<div>
							<button type="button" class="fg-thumb" data-index="<?php echo (int) $i; ?>"
									data-full="<?php echo $esc($image['full']); ?>"
									aria-label="<?php echo $esc($image['name']); ?>">
								<div class="uk-cover-container">
									<canvas width="1" height="1"></canvas>
									<img src="<?php echo $esc($image['thumb']); ?>" alt="<?php echo $esc($image['name']); ?>"
										uk-cover loading="lazy" decoding="async">
								</div>
							</button>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ($initial['pages'] > 1) : ?>
					<div class="fg-loadmore-wrap">
						<button type="button" class="fg-loadmore uk-button uk-button-default" data-page="1" data-pages="<?php echo (int) $initial['pages']; ?>">
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
