/**
 * mod_folder_gallery
 * (C) Pixed - pixed.it
 * Lazy folder navigation + on-demand image pages + lightbox.
 *
 * One navigation core drives two layout skins (default `fg-*` markup / UIkit 3). The current
 * folder is reflected in the URL (?fgpath=) for shareable links and Back/Forward navigation.
 */
(function () {
	'use strict';

	var URL_KEY = 'fgpath';

	var SVG_FOLDER =
		'<svg viewBox="0 0 24 24" width="32" height="32" aria-hidden="true" focusable="false">' +
		'<path fill="currentColor" d="M10 4H2v16h20V6H12l-2-2z"/></svg>';

	var SVG_BACK =
		'<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">' +
		'<path fill="currentColor" d="M14 7l-5 5 5 5V7z"/></svg>';

	function parentPath(path) {
		if (!path) { return ''; }
		var i = path.lastIndexOf('/');
		return i === -1 ? '' : path.slice(0, i);
	}

	function el(tag, cls, attrs) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (attrs) {
			Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
		}
		return node;
	}

	// HTML-escape a string for sinks that render HTML (e.g. the UIkit lightbox caption, which uses innerHTML).
	function escapeHtml(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	// Resolve the global UIkit, allowing for YOOtheme's conditional/late script load.
	// Calls cb(UIkit) when available, or cb(null) after a short timeout so callers can fall back.
	function whenUIkit(cb) {
		if (window.UIkit) { cb(window.UIkit); return; }

		var done = false;
		function fire() { if (done) { return; } done = true; cb(window.UIkit || null); }

		document.addEventListener('uikit:init', fire, { once: true });

		var tries = 0;
		var iv = setInterval(function () {
			if (window.UIkit || tries++ > 20) { clearInterval(iv); fire(); }
		}, 100);
	}

	function Gallery(root, cfg) {
		this.root = root;
		this.cfg = cfg;
		this.framework = cfg.framework === 'uikit' ? 'uikit' : 'default';
		this.body = root.querySelector('.fg-body');
		this.crumbs = root.querySelector('.fg-breadcrumb');
		this.state = { path: '', page: 1, pages: 1 };
		this.lightbox = null;
		this.lbIndex = 0;
		this.lbImages = [];
		this.lbCache = {};
		this.bind();
		this.bindHistory();
		this.restore();
	}

	Gallery.prototype.bind = function () {
		var self = this;

		this.root.addEventListener('click', function (e) {
			var folder = e.target.closest('.fg-folder');
			if (folder && self.root.contains(folder)) {
				e.preventDefault();
				self.go(folder.getAttribute('data-path'), true);
				return;
			}

			var crumb = e.target.closest('.fg-crumb');
			if (crumb && self.root.contains(crumb)) {
				e.preventDefault();
				self.go(crumb.getAttribute('data-path'), true);
				return;
			}

			var back = e.target.closest('.fg-back');
			if (back && self.root.contains(back)) {
				e.preventDefault();
				self.go(back.getAttribute('data-path'), true);
				return;
			}

			var more = e.target.closest('.fg-loadmore');
			if (more && self.root.contains(more)) {
				e.preventDefault();
				self.browse(self.state.path, self.state.page + 1, true);
				return;
			}

			var thumb = e.target.closest('.fg-thumb');
			if (thumb && self.root.contains(thumb)) {
				e.preventDefault();
				self.openLightbox(parseInt(thumb.getAttribute('data-index'), 10) || 0);
			}
		});
	};

	/* ----- URL / history (shareable links + Back/Forward) ----- */

	Gallery.prototype.buildUrl = function (path) {
		var url;
		try { url = new URL(window.location.href); }
		catch (e) { return window.location.href; }

		if (path) { url.searchParams.set(URL_KEY, path); }
		else { url.searchParams.delete(URL_KEY); }

		return url.pathname + url.search + url.hash;
	};

	Gallery.prototype.readPathFromUrl = function () {
		try { return new URL(window.location.href).searchParams.get(URL_KEY) || ''; }
		catch (e) { return ''; }
	};

	// Navigate to a folder; when push is true, add a history entry so the address bar is shareable.
	Gallery.prototype.go = function (path, push) {
		path = path || '';
		if (push && window.history && history.pushState) {
			history.pushState({ fgPath: path }, '', this.buildUrl(path));
		}
		this.browse(path, 1, false);
	};

	Gallery.prototype.bindHistory = function () {
		var self = this;
		if (!window.history || !history.pushState) { return; }

		window.addEventListener('popstate', function (e) {
			var path = (e.state && typeof e.state.fgPath === 'string') ? e.state.fgPath : self.readPathFromUrl();
			self.browse(path, 1, false);
		});
	};

	// On load, open the deep-linked folder (if any). Server already rendered the root level.
	Gallery.prototype.restore = function () {
		var path = this.readPathFromUrl();

		if (history.replaceState) {
			history.replaceState({ fgPath: path }, '', this.buildUrl(path));
		}

		if (path) {
			this.showLoading();
			this.browse(path, 1, false);
		}
	};

	/* ----- Browse / AJAX ----- */

	Gallery.prototype.browse = function (path, page, append) {
		var self = this;
		var url = this.cfg.ajaxUrl
			+ '&modid=' + encodeURIComponent(this.cfg.modid)
			+ '&path=' + encodeURIComponent(path || '')
			+ '&page=' + encodeURIComponent(page || 1);

		var moreBtn = append ? this.body.querySelector('.fg-loadmore') : null;

		if (append) {
			this.setLoadMoreLoading(moreBtn, true);
		} else {
			this.showLoading();
		}

		fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json || json.success !== true || !json.data) {
					throw new Error((json && json.message) || self.cfg.i18n.error);
				}
				self.render(json.data, append);
				self.setLoadMoreLoading(moreBtn, false);
			})
			.catch(function () {
				if (append) {
					// Keep the already-loaded images; just reset the button so the user can retry.
					self.setLoadMoreLoading(moreBtn, false);
				} else {
					self.showError();
				}
			});
	};

	Gallery.prototype.setLoadMoreLoading = function (btn, on) {
		if (!btn) { return; }
		if (on) {
			btn.classList.add('is-loading');
			btn.disabled = true;
		} else {
			btn.classList.remove('is-loading');
			btn.disabled = false;
		}
	};

	Gallery.prototype.showLoading = function () {
		this.body.innerHTML = '';
		var p = el('p', 'fg-message fg-message--loading');

		if (this.framework === 'uikit') {
			p.appendChild(el('span', null, { 'uk-spinner': '' }));
			p.appendChild(document.createTextNode(' ' + this.cfg.i18n.loading));
		} else {
			p.appendChild(el('span', 'fg-spinner', { 'aria-hidden': 'true' }));
			p.appendChild(document.createTextNode(this.cfg.i18n.loading));
		}

		this.body.appendChild(p);
	};

	Gallery.prototype.showError = function () {
		this.body.innerHTML = '';
		var p = el('p', 'fg-message fg-message--error');
		p.textContent = this.cfg.i18n.error;
		this.body.appendChild(p);
	};

	/* ----- Breadcrumb ----- */

	Gallery.prototype.renderBreadcrumb = function (rel) {
		if (this.framework === 'uikit') { this.renderBreadcrumbUikit(rel); return; }

		var self = this;
		this.crumbs.innerHTML = '';

		var root = el('button', 'fg-crumb', { type: 'button', 'data-path': '' });
		root.textContent = this.cfg.rootLabel;
		this.crumbs.appendChild(root);

		if (!rel) { return; }

		var parts = rel.split('/');
		var acc = '';
		parts.forEach(function (part) {
			acc = acc ? (acc + '/' + part) : part;
			var sep = el('span', 'fg-crumb-sep', { 'aria-hidden': 'true' });
			sep.textContent = '/';
			self.crumbs.appendChild(sep);

			var c = el('button', 'fg-crumb', { type: 'button', 'data-path': acc });
			c.textContent = part;
			self.crumbs.appendChild(c);
		});
	};

	Gallery.prototype.renderBreadcrumbUikit = function (rel) {
		this.crumbs.innerHTML = '';
		var ul = el('ul', 'uk-breadcrumb');
		this.crumbs.appendChild(ul);

		var items = [{ name: this.cfg.rootLabel, path: '' }];
		if (rel) {
			var acc = '';
			rel.split('/').forEach(function (part) {
				acc = acc ? (acc + '/' + part) : part;
				items.push({ name: part, path: acc });
			});
		}

		items.forEach(function (it, i) {
			var li = el('li');
			if (i === items.length - 1) {
				var span = el('span');
				span.textContent = it.name;
				li.appendChild(span);
			} else {
				var a = el('a', 'fg-crumb', { href: '#', 'data-path': it.path });
				a.textContent = it.name;
				li.appendChild(a);
			}
			ul.appendChild(li);
		});
	};

	/* ----- Render ----- */

	Gallery.prototype.gridClass = function (base) {
		if (this.framework !== 'uikit') { return base; }
		var cols = parseInt(this.cfg.cols, 10) || 4;
		return base + ' uk-grid-small uk-child-width-1-2 uk-child-width-1-' + cols + '@m';
	};

	Gallery.prototype.render = function (data, append) {
		this.state.path = data.rel || '';
		this.state.page = data.page || 1;
		this.state.pages = data.pages || 1;

		if (append) {
			this.appendImages(data.images);
			this.updateLoadMore();
			return;
		}

		this.renderBreadcrumb(this.state.path);
		this.body.innerHTML = '';

		var uk = this.framework === 'uikit';
		var self = this;

		// Inside a sub-folder: a "back to parent" button, both before and after the content.
		var inSub = !!this.state.path;
		if (inSub) { this.body.appendChild(this.makeBackButton('top')); }

		if ((!data.folders || !data.folders.length) && (!data.images || !data.images.length)) {
			var empty = el('p', 'fg-message');
			empty.textContent = this.cfg.i18n.empty;
			this.body.appendChild(empty);
		} else {
			if (data.folders && data.folders.length) {
				var fwrap = el('div', this.gridClass('fg-folders'));
				if (uk) { fwrap.setAttribute('uk-grid', ''); }

				data.folders.forEach(function (f) {
					var btn = el('button', uk ? 'fg-folder uk-button uk-button-default' : 'fg-folder', { type: 'button', 'data-path': f.path });
					var icon = el('span', 'fg-folder-icon');
					icon.innerHTML = SVG_FOLDER;
					var name = el('span', 'fg-folder-name');
					name.textContent = f.name;
					btn.appendChild(icon);
					btn.appendChild(name);
					if (f.count !== null && typeof f.count !== 'undefined') {
						var cnt = el('span', 'fg-folder-count');
						cnt.textContent = f.count;
						btn.appendChild(cnt);
					}
					fwrap.appendChild(self.cell(btn));
				});
				this.body.appendChild(fwrap);
			}

			if (data.images && data.images.length) {
				var grid = el('div', this.gridClass('fg-grid'));
				if (uk) { grid.setAttribute('uk-grid', ''); }
				this.body.appendChild(grid);
				this.appendImages(data.images);

				var wrap = el('div', 'fg-loadmore-wrap');
				var more = el('button', uk ? 'fg-loadmore uk-button uk-button-default' : 'fg-loadmore', { type: 'button' });
				more.textContent = this.cfg.i18n.loadmore;
				wrap.appendChild(more);
				this.body.appendChild(wrap);
				this.updateLoadMore();
			}
		}

		if (inSub) { this.body.appendChild(this.makeBackButton('bottom')); }
	};

	// In UIkit a grid's direct children are the cells; the default skin places items directly.
	Gallery.prototype.cell = function (node) {
		if (this.framework !== 'uikit') { return node; }
		var div = el('div');
		div.appendChild(node);
		return div;
	};

	Gallery.prototype.makeBackButton = function (pos) {
		var uk = this.framework === 'uikit';
		var btn = el('button', (uk ? 'fg-back uk-button uk-button-link ' : 'fg-back ') + 'fg-back--' + pos, {
			type: 'button',
			'data-path': parentPath(this.state.path)
		});

		var icon = el('span', 'fg-back-icon');
		if (uk) { icon.setAttribute('uk-icon', 'chevron-left'); }
		else { icon.innerHTML = SVG_BACK; }

		var label = el('span', 'fg-back-label');
		label.textContent = this.cfg.i18n.back;

		btn.appendChild(icon);
		btn.appendChild(label);
		return btn;
	};

	Gallery.prototype.appendImages = function (images) {
		var grid = this.body.querySelector('.fg-grid');
		if (!grid) { return; }

		var uk = this.framework === 'uikit';
		var self = this;
		var start = grid.querySelectorAll('.fg-thumb').length;

		(images || []).forEach(function (img, i) {
			var btn = el('button', 'fg-thumb', {
				type: 'button',
				'data-index': start + i,
				'data-full': img.full,
				'aria-label': img.name
			});

			if (uk) {
				var cont = el('div', 'uk-cover-container');
				cont.appendChild(el('canvas', null, { width: '1', height: '1' }));
				cont.appendChild(el('img', null, {
					src: img.thumb, alt: img.name, loading: 'lazy', decoding: 'async', 'uk-cover': ''
				}));
				btn.appendChild(cont);
			} else {
				btn.appendChild(el('img', null, {
					src: img.thumb, alt: img.name, loading: 'lazy', decoding: 'async'
				}));
			}

			grid.appendChild(self.cell(btn));
		});
	};

	Gallery.prototype.updateLoadMore = function () {
		var wrap = this.body.querySelector('.fg-loadmore-wrap');
		if (!wrap) { return; }
		wrap.style.display = (this.state.page >= this.state.pages) ? 'none' : '';
	};

	/* ----- Lightbox: full-folder image list (shared), then skin-specific display ----- */

	// Fallback: build the lightbox set from the thumbs currently in the grid.
	Gallery.prototype.collectImages = function () {
		var thumbs = this.body.querySelectorAll('.fg-thumb');
		this.lbImages = Array.prototype.map.call(thumbs, function (t) {
			return { full: t.getAttribute('data-full'), name: t.getAttribute('aria-label') };
		});
	};

	// Loads the full ordered image list of the current folder (names + full URLs only), so the
	// lightbox slides through every image — not just the paginated grid. Cached per folder.
	Gallery.prototype.loadFolderImages = function (cb) {
		var self = this;
		var path = this.state.path || '';

		if (this.lbCache[path]) {
			cb(this.lbCache[path]);
			return;
		}

		this.showLightboxLoading();

		var url = this.cfg.listUrl
			+ '&modid=' + encodeURIComponent(this.cfg.modid)
			+ '&path=' + encodeURIComponent(path);

		fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (!json || json.success !== true || !json.data || !json.data.images) {
					throw new Error('bad response');
				}
				self.lbCache[path] = json.data.images;
				cb(json.data.images);
			})
			.catch(function () {
				self.collectImages();
				cb(self.lbImages);
			});
	};

	Gallery.prototype.openLightbox = function (index) {
		if (this.framework === 'uikit') { this.openUikitLightbox(index); }
		else { this.openCustomLightbox(index); }
	};

	/* UIkit lightbox: open straight from the folder's image list (no pre-rendered anchors). */
	Gallery.prototype.openUikitLightbox = function (index) {
		var self = this;
		this.loadFolderImages(function (images) {
			images = images || [];
			if (!images.length) { return; }

			whenUIkit(function (UIkit) {
				if (UIkit && UIkit.lightboxPanel) {
					// UIkit injects `caption` as HTML (innerHTML): escape the file name so it is always shown as text.
					var items = images.map(function (im) { return { source: im.full, caption: escapeHtml(im.name) }; });
					var idx = Math.max(0, Math.min(index, items.length - 1));
					UIkit.lightboxPanel({ items: items, index: idx }).show();
				} else {
					// No UIkit on this page — fall back to the built-in lightbox.
					self.displayCustom(images, index);
				}
			});
		});
	};

	/* Custom (default-skin) lightbox. */
	Gallery.prototype.openCustomLightbox = function (index) {
		var self = this;
		if (!this.lightbox) { this.buildLightbox(); }

		this.lightbox.removeAttribute('hidden');
		document.body.classList.add('fg-lb-open');
		this.lightbox.querySelector('.fg-lb-close').focus();

		this.loadFolderImages(function (images) {
			self.displayCustom(images || [], index);
		});
	};

	Gallery.prototype.displayCustom = function (images, index) {
		if (!this.lightbox) {
			this.buildLightbox();
			this.lightbox.removeAttribute('hidden');
			document.body.classList.add('fg-lb-open');
		}

		this.lbImages = images || [];
		if (!this.lbImages.length) { this.closeLightbox(); return; }
		this.lbIndex = Math.max(0, Math.min(index, this.lbImages.length - 1));
		this.showCurrent();
	};

	Gallery.prototype.showLightboxLoading = function () {
		if (!this.lightbox) { return; }
		var imgEl = this.lightbox.querySelector('.fg-lb-img');
		var cap = this.lightbox.querySelector('.fg-lb-caption');
		imgEl.removeAttribute('src');
		imgEl.alt = '';
		cap.textContent = this.cfg.i18n.loading;
	};

	Gallery.prototype.buildLightbox = function () {
		var self = this;
		var lb = el('div', 'fg-lightbox', { role: 'dialog', 'aria-modal': 'true', hidden: 'hidden' });

		// Built with DOM APIs (no HTML string interpolation) so i18n strings can never break the markup.
		var close = el('button', 'fg-lb-close', { type: 'button', 'aria-label': self.cfg.i18n.close });
		close.textContent = '×';

		var prev = el('button', 'fg-lb-nav fg-lb-prev', { type: 'button', 'aria-label': self.cfg.i18n.prev });
		prev.textContent = '‹';

		var figure = el('figure', 'fg-lb-figure');
		figure.appendChild(el('img', 'fg-lb-img', { alt: '' }));
		figure.appendChild(el('figcaption', 'fg-lb-caption'));

		var next = el('button', 'fg-lb-nav fg-lb-next', { type: 'button', 'aria-label': self.cfg.i18n.next });
		next.textContent = '›';

		lb.appendChild(close);
		lb.appendChild(prev);
		lb.appendChild(figure);
		lb.appendChild(next);

		document.body.appendChild(lb);

		lb.querySelector('.fg-lb-close').addEventListener('click', function () { self.closeLightbox(); });
		lb.querySelector('.fg-lb-prev').addEventListener('click', function () { self.step(-1); });
		lb.querySelector('.fg-lb-next').addEventListener('click', function () { self.step(1); });
		lb.addEventListener('click', function (e) { if (e.target === lb) { self.closeLightbox(); } });

		this.keyHandler = function (e) {
			if (lb.hasAttribute('hidden')) { return; }
			if (e.key === 'Escape') { self.closeLightbox(); }
			else if (e.key === 'ArrowLeft') { self.step(-1); }
			else if (e.key === 'ArrowRight') { self.step(1); }
		};
		document.addEventListener('keydown', this.keyHandler);

		// Basic touch swipe.
		var x0 = null;
		lb.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
		lb.addEventListener('touchend', function (e) {
			if (x0 === null) { return; }
			var dx = e.changedTouches[0].clientX - x0;
			if (Math.abs(dx) > 40) { self.step(dx < 0 ? 1 : -1); }
			x0 = null;
		});

		this.lightbox = lb;
	};

	Gallery.prototype.closeLightbox = function () {
		if (!this.lightbox) { return; }
		this.lightbox.setAttribute('hidden', 'hidden');
		document.body.classList.remove('fg-lb-open');
	};

	Gallery.prototype.step = function (dir) {
		if (!this.lbImages.length) { return; }
		this.lbIndex = (this.lbIndex + dir + this.lbImages.length) % this.lbImages.length;
		this.showCurrent();
	};

	Gallery.prototype.showCurrent = function () {
		var img = this.lbImages[this.lbIndex];
		var imgEl = this.lightbox.querySelector('.fg-lb-img');
		var cap = this.lightbox.querySelector('.fg-lb-caption');
		imgEl.src = img.full;
		imgEl.alt = img.name;
		cap.textContent = img.name;
	};

	/* ----- Boot ----- */

	function boot() {
		if (!window.Joomla || typeof Joomla.getOptions !== 'function') { return; }
		var all = Joomla.getOptions('mod_folder_gallery', {});

		Object.keys(all).forEach(function (modid) {
			var cfg = all[modid];
			var root = document.getElementById(cfg.el);
			if (root && !root.__fgInit) {
				root.__fgInit = true;
				new Gallery(root, cfg);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
