# Folder Gallery (`mod_folder_gallery`)

A Joomla site module that turns a folder tree on your server into a browsable image gallery:
sub-folders become albums, images are shown in a lazy-loaded grid with a lightbox, and nothing is
scanned until it is needed. Built for large archives.

- **One folder level at a time.** The page load scans only the top level; sub-folders and extra
  pages are fetched on demand (`com_ajax`).
- **Thumbnails on demand.** Generated with GD (WebP when available, else JPEG) only for the images
  actually displayed, then cached on disk.
- **Lightbox over the whole folder**, not just the loaded page.
- **Shareable URLs.** The current folder is mirrored in `?fgpath=…`; Back/Forward work.
- **Two layout skins:** the module's own CSS + lightbox, or native **UIkit 3** markup and
  lightbox for YOOtheme / UIkit templates (no extra assets).
- Runs natively on Joomla 6 with the *Behaviour - Backward Compatibility* plugin disabled.

## Requirements

| | |
|---|---|
| Joomla | 4.4, 5.x, 6.x |
| PHP | 8.1 or newer, with the **GD** extension (optional but strongly recommended: without GD the grid shows the full-size originals) and `mbstring` |
| Web server | the base folder and the cache folder must be inside the site root and web-accessible |

## Installation

1. Download the latest release ZIP (or zip the repository contents: `services/`, `src/`, `tmpl/`,
   `language/`, `media/` and `mod_folder_gallery.xml`).
2. In the Joomla administrator go to **System → Install → Extensions** and upload the ZIP.
3. **Content → Site Modules → New → Folder Gallery**, set the *Base folder*, assign a position and
   menu items, and publish.

Updating is done the same way (the manifest uses `method="upgrade"`).

## Options

| Option | Default | Notes |
|---|---|---|
| Base folder | `images` | Path relative to the site root, e.g. `images/gallery`. No spaces (Joomla's path filter rejects them). Joomla system folders (`administrator`, `libraries`, `cache`, `tmp`, …) are refused. |
| Columns | 4 | Grid columns for folders and images. |
| Layout style | Default | *Default* = module CSS + own lightbox. *UIkit 3* = your theme's UIkit classes and lightbox. |
| Thumbnail mode | Cover | *Cover* crops to a square cell, *Contain* fits the whole image. |
| Thumbnail width / height | 500 × 500 px | 32–2000. |
| Images per page | 24 | 1–500. Lower values keep huge folders fast. |
| Show image counts | No | Counts images in each sub-folder (one extra scan per sub-folder). |
| Thumbnail cache folder | `images/cache_folder_gallery` | Must be writable and web-accessible. Created automatically. |

Supported image extensions: `jpg`, `jpeg`, `png`, `gif`, `webp`, `avif`.
Files and folders whose name starts with a dot are ignored.

## Security model — please read before pointing it at a folder

- **Everything under the base folder is public.** Folder names and image file names are listed to
  every visitor who can see the module, and the image files are static files served directly by
  your web server regardless of the module. Never point the module at a folder that contains
  anything private.
- The AJAX endpoints enforce the module instance's **access level** and publishing dates, so a
  gallery restricted to *Registered* users cannot be browsed anonymously through the API. They
  are **not** an access-control layer for the files themselves.
- User-supplied paths are normalised, checked segment by segment and confined to the base folder
  with `realpath()`; symlinks that leave the base folder are refused.
- Thumbnail generation is bounded (40 MP source limit and a `memory_limit` estimate): an
  oversized image or a decompression bomb falls back to serving the original instead of crashing.
- The thumbnail cache folder is web-accessible by design and receives an `index.html` stub.
  Thumbnails are never purged automatically; delete the cache folder to regenerate them.
- Recommended server-side hygiene: keep directory indexes off and consider rate limiting
  `index.php?option=com_ajax` if the gallery is very large.

Found a problem? See [SECURITY.md](SECURITY.md).

## Developing

There is no build step. Copy `src/`, `tmpl/`, `services/`, `language/` into
`modules/mod_folder_gallery/` and `media/` into `media/mod_folder_gallery/` of a Joomla install, or
install the ZIP. After editing PHP, clear Joomla's cache (directory listings are cached).

The JavaScript (`media/js/folder_gallery.js`) builds the same markup as the two PHP templates;
keep them in sync when changing the DOM. All strings go through Joomla language files
(`language/en-GB`, `language/it-IT`).

## License

GNU General Public License version 2 or later. See [LICENSE.txt](LICENSE.txt).

---

## In italiano

**Folder Gallery** è un modulo Joomla (4.4 / 5 / 6, PHP 8.1+ con GD) che mostra una cartella del
server come galleria: le sottocartelle diventano album, le immagini una griglia lazy con lightbox.
Scansiona un solo livello per volta e genera le miniature solo per le immagini visibili, quindi
regge anche archivi molto grandi. Supporta due stili: CSS proprio oppure markup e lightbox
**UIkit 3** per i siti YOOtheme.

**Installazione:** carica lo ZIP da *Sistema → Installa → Estensioni*, poi crea un modulo
*Folder Gallery*, imposta la *Cartella base* (es. `images/galleria`, senza spazi) e pubblicalo.

**Sicurezza — da leggere:** tutto ciò che sta sotto la cartella base è pubblico (nomi di cartelle
e file vengono elencati a chiunque veda il modulo, e le immagini sono file statici serviti dal web
server). Non puntare mai il modulo su una cartella con contenuti riservati. Gli endpoint AJAX
rispettano il livello di accesso e le date di pubblicazione del modulo, i percorsi sono confinati
alla cartella base, le cartelle di sistema di Joomla sono rifiutate e la generazione delle
miniature è limitata (40 MP + stima della memoria) per evitare errori fatali. La cartella cache è
pubblica per costruzione. Per segnalare una vulnerabilità scrivi a **info@pixed.it** (vedi
`SECURITY.md`), non aprire una issue pubblica.
