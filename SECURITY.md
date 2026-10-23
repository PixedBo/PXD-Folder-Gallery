# Security Policy

## Supported versions

Only the latest release on the `main` branch receives security fixes.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security problems.

Email **info@pixed.it** with:

- a description of the issue and its impact,
- the module version and Joomla / PHP versions,
- steps or a proof of concept to reproduce it.

You will get an acknowledgement within a few days. Once a fix is released the issue
will be credited to you in the changelog unless you prefer otherwise.

## Security model (what the module does and does not protect)

- Everything below the configured **base folder** is intentionally public: folder names and
  image file names are listed to any visitor, and the images themselves are static files served
  directly by the web server. Do **not** point the module at a folder that contains anything
  private.
- The module enforces the Joomla *access level* and publishing dates of each module instance
  on its AJAX endpoints, but it is **not** an access-control layer for the image files.
- The thumbnail cache folder is web-accessible by design.
- User-supplied paths are confined to the base folder (segment check + `realpath` containment);
  Joomla system directories cannot be used as base or cache folder.
