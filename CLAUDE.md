# Storer Motors — storermotors.co.nz

WordPress site for Storer Motors Ltd, a New Zealand used-car dealership. Vehicle
inventory syncs automatically from the dealer's Motorcentral/SML feed;
everything else is edited in the block editor.

## Repo scope — read this first

**This repo tracks custom code only.** WordPress core (`wp-admin/`,
`wp-includes/`, root `wp-*.php`), third-party plugins, the bundled Twenty*
themes and `wp-content/uploads/` are all gitignored. They exist in the working
tree but git does not manage them.

Consequences:

- The repo cannot rebuild a running site on its own. It layers onto an existing
  WordPress install.
- Ignored files are untracked, not deleted — `git pull` and `git push` leave the
  WordPress install alone. Never run `git clean -xdf` in this tree; it *would*
  delete core.
- `wp-config.php` is ignored (live DB password, salts, and the feed FTP
  credentials). `wp-config-sample.php` is tracked. Never commit the real one.

## Where the code lives

All custom code is in `wp-content/mu-plugins/` — must-use plugins, so they load
unconditionally and can't be deactivated from the admin. There is **no custom
theme**: the site runs the stock `twentytwentyfive` block theme, and all
templates and page content live in the **database**, not in files.

That last point matters: a page layout you're asked to change is usually a block
template in the DB, editable only through the Site Editor. What lives in code is
the CSS, the blocks, the CPT, and the importer.

```
wp-content/mu-plugins/
├── storermotors-branding.php          ~3.8k lines — the bulk of the front end
├── storermotors-pagination.php        inventory archive pagination styling
├── storermotors-importer.php          stub; requires the class files below
└── storermotors-importer/             the vehicle feed importer
    ├── class-sm-import-settings.php   settings resolution (constants > options)
    ├── class-sm-import-log.php        run logging + admin notices
    ├── class-sm-import-package.php    ZIP validation / extraction
    ├── class-sm-import-parser.php     SML.XML → normalised vehicle data
    ├── class-sm-import-vehicles.php   upsert + reconcile the `vehicle` CPT
    ├── class-sm-import-media.php      photo sideloading, sliced across cron
    ├── class-sm-import-local.php      local drop-folder pickup (the live source)
    ├── class-sm-import-ftp.php        FTP/FTPS pull (the alternative source)
    ├── class-sm-import-runner.php     orchestration, locking, cron wiring
    └── class-sm-import-admin.php      settings screen under Vehicles
```

`docs/` holds the longer-form guides — start at [`docs/README.md`](docs/README.md).

`reference/` holds the original static HTML mockups (`*-build.html`) and their
approved checkpoints (`*-checkpoint.html`) used to build the block templates.
They're historical reference, not live code.

## storermotors-branding.php

One file, sectioned with `// ─── SECTION ───` banners. In order: the `vehicle`
CPT and `vehicle_type` taxonomy, vehicle meta fields and meta boxes, the
featured-vehicles shortcode, custom blocks, front-end assets, the two Contact
Form 7 forms (finance application, trade-in), editor assets, then ~2.5k lines of
CSS and the JS for vehicle filters and the gallery.

- CSS and JS are inlined via `wp_add_inline_style` / `wp_add_inline_script` on
  the empty registered handles `sm-branding` (front end) and
  `sm-branding-editor` / `sm-editor-layout` (editor). There are no `.css` or
  `.js` asset files to edit.
- Design tokens are CSS custom properties on `:root` near the top of the CSS
  section: `--sm-yellow` `#F5C518`, `--sm-black` `#111`, `--sm-border`,
  `--sm-max` `1300px`, `--sm-radius`, `--sm-shadow*`. Use the tokens rather than
  hard-coding hexes.
- Vehicle meta keys are underscore-prefixed (`_vehicle_price`, `_vehicle_year`,
  `_vehicle_make`, `_vehicle_odometer`, `_vehicle_sold`, …) and registered with
  `show_in_rest` plus an `auth_callback` returning true, so block bindings
  (`core/post-meta`) can read them despite being protected keys.
- `vehicle` archive slug is `/inventory`; taxonomy slug is `/vehicle-type`.
- Custom blocks, all registered here: `sm/vehicle-price`, `sm/vehicle-weekly`,
  `sm/vehicle-specs`, `sm/vehicle-details`, `sm/vehicle-gallery`,
  `sm/vehicle-filters`, `sm/featured-vehicles`.

**This file has diverged between the local tree and the live host.** A copy
pulled off the server may sit alongside it as `storermotors-branding.php1`
(gitignored). Before overwriting the whole file, diff against the live version —
that divergence is why `storermotors-pagination.php` exists as a separate file,
and additive front-end work should keep following that pattern rather than
growing the big file.

## The vehicle feed importer

Pulls the Motorcentral/SML package over FTP and syncs it into the `vehicle` CPT.
**Full guide: [`docs/vehicle-feed-import.md`](docs/vehicle-feed-import.md)** —
FTP setup, field mapping, photo sync, reconciliation guards, settings and
troubleshooting. The summary below is orientation only.

Pipeline, deliberately fail-closed and in this order:

    download → verify → extract → parse → validation gate → upsert
             → enqueue images → reconcile

Reconciliation (unpublishing vehicles absent from the feed) runs **last** and
only after a clean parse, so a truncated or malformed feed can never empty the
website. Guard ratios in `SM_Import_Settings::defaults()` — `min_ratio`,
`max_draft_ratio`, `max_skip_ratio` — abort a run whose shape looks wrong.

Feed shape: an outer ZIP containing `SML.XML` (vehicle data), `SML-NN-Data.ZIP`
(flat `{StockNo}_{n}.jpg` photos) and `END.XML` (transfer-complete sentinel).
Both archives must be flat — any entry name with a path separator is treated as
hostile and aborts the run.

- **The live source is a local drop folder**, not an FTP pull: Motorcentral
  uploads into `orion_sync` (a sibling of `httpdocs` on the Plesk box) over their
  own restricted FTP account, and `SM_Import_Local` reads it off disk. Configured
  with one constant, `SM_FEED_LOCAL_PATH`. A configured drop folder wins over
  every FTP setting.
- `SM_Import_FTP` remains for pulling from a remote server. Its credentials come
  from `wp-config.php` constants (`SM_FEED_FTP_HOST`, `_USER`, `_PASS`, `_PATH`,
  `_SCHEME`), which win over the `sm_vehicle_import_settings` option so the
  password never reaches the database or a SQL dump. Keep it that way — don't add
  a code path that persists a password to options.
- Cron: custom schedules `sm_every_15min` / `sm_hourly` (default) /
  `sm_twice_daily` / `sm_daily` (anchored on `SM_Import_Runner::DAILY_HOUR`, 5am
  local, re-anchored after each run so DST can't drift it); hooks
  `sm_vehicle_import_check`,
  `sm_vehicle_import_process_images` (image sideloading is sliced across
  single events to stay inside PHP limits), `sm_vehicle_import_gc`.
- A run holds the `sm_vehicle_import_lock` option, timing out after 30 min.
  `sm_vehicle_import_last_fingerprint` skips an unchanged feed.
- Admin screen: **Vehicles → Feed Import** (`edit.php?post_type=vehicle&page=sm-vehicle-import`),
  `manage_options` only.
- Staging and logs live under `wp-content/uploads/sm-vehicle-import/`
  (gitignored).
- `class-sm-import-parser.php` is the single source of truth for every value
  mapping (transmission codes, fuel types, body styles). The `sm/vehicle-filters`
  block builds its dropdowns from live `DISTINCT` meta with no allowlist of its
  own, so an unrecognised feed value must be discarded and logged, never written
  through to the database.

## Conventions

- PHP: 4-space indent, `if (!defined('ABSPATH')) { exit; }` at the top of every
  file, `final class` with static methods and `const` config, `SM_` class prefix
  and `sm_` prefix for hooks, options and function names.
- Escape on output (`esc_html`, `esc_attr`, `esc_url`), sanitize on input,
  nonce-check admin POSTs — follow the existing patterns in
  `class-sm-import-admin.php`.
- Prefer a new small mu-plugin file over appending to
  `storermotors-branding.php`, given the divergence noted above.

## Environment

- **Production is Plesk**, document root `httpdocs`, with nginx in front of
  Apache — so `.htaccess` deny rules do not necessarily apply to static files.
  Vhost layout: `/var/www/vhosts/storermotors.co.nz/{httpdocs,orion_sync}`.
- The `.htaccess` in this tree carries a cPanel-generated `ea-php81` handler
  block, left over from a cPanel host. It is inert on Plesk.
- Plugins in use: Contact Form 7 + Flamingo (form storage), WP Mail SMTP,
  All in One SEO, Safe SVG, WPCode/insert-headers-and-footers, Akismet.
  All third-party and gitignored — update them through the admin, not git.
