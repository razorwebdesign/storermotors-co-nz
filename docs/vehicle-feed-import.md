# Vehicle Feed Importer

How the Motorcentral/SML vehicle feed gets from the dealer's DMS onto
storermotors.co.nz — what it does, how to set it up, and what to check when it
misbehaves.

**Code:** `wp-content/mu-plugins/storermotors-importer.php` (loader) and
`wp-content/mu-plugins/storermotors-importer/` (nine classes).
**Admin screen:** Vehicles → Feed Import (`edit.php?post_type=vehicle&page=sm-vehicle-import`), `manage_options` only.

---

## 1. What it does

On a schedule the site checks the drop folder Motorcentral uploads into. When a
new package is waiting it stages the archive, parses the vehicle XML, and syncs
it into the `vehicle` custom post type: creating new listings, updating changed
ones, importing photos, and moving sold vehicles to draft.

Two sources are supported — a **local drop folder** (`class-sm-import-local.php`,
what this site uses) and a **remote FTP pull** (`class-sm-import-ftp.php`). They
share the same readiness contract, fingerprinting and staging behaviour; the rest
of the pipeline can't tell them apart.

Nothing about it is interactive. The dealer works in Motorcentral; the website
follows. The admin screen exists for setup, monitoring, and manual overrides.

The design priority throughout is **fail closed**: a bad feed must never empty
the website. Every destructive step is last, guarded, and reversible.

---

## 2. Setup

### 2.1 The drop folder

Motorcentral uploads the package onto this server over their own restricted FTP
account, and the importer reads it off disk. No outbound connection, no FTP
credentials stored by the site.

The folder is a sibling of the document root:

```
/var/www/vhosts/storermotors.co.nz/
  ├─ httpdocs/       the website
  └─ orion_sync/     Motorcentral drops the package here
```

Confirm the real vhost path in Plesk (Domains → File Manager, or `pwd` after an
FTP login). Plesk names the directory after the subscription's primary domain,
which is not always the domain you expect.

Being **outside `httpdocs` is deliberate.** Plesk fronts Apache with nginx, and
with "Serve static files directly by nginx" enabled — the default — nginx serves
a `.zip` without ever consulting `.htaccess`. A 23 MB archive of the entire
inventory inside the document root would be a public download. A sibling folder
isn't web-reachable at all, so there's nothing to configure and nothing to get
wrong later.

It is still inside the vhost root, which matters for `open_basedir`: Plesk sets
that to the webspace root plus tmp, so `orion_sync` is readable. If PHP reports
an `open_basedir` error, add the path under Domains → PHP Settings.

Don't use `wp-content/uploads/sm-vehicle-import/` as the drop folder — that's the
importer's private staging area, and it gets garbage-collected.

### 2.2 The vendor's FTP account

Plesk: Domains → **FTP Access** → Add an FTP Account.

| Field | Value |
| --- | --- |
| Home directory | `/orion_sync` |
| Permissions | read/write, that folder only |

Create it under this subscription so it maps to the same system user PHP runs
as — then PHP can read what the vendor writes and delete the sentinel with no
permission work at all. If the account maps to a different user, PHP needs read
on the folder and write on the folder (to remove `END.XML`).

Those credentials go to Motorcentral and nowhere else. WordPress never uses them.

### 2.3 Configuration

One line in `wp-config.php` on the live server, between the
`/* Add any custom values… */` marker and the "stop editing" line:

```php
define( 'SM_FEED_LOCAL_PATH', '/var/www/vhosts/storermotors.co.nz/orion_sync' );
```

That's the whole configuration. The constant beats the options row
(`SM_Import_Settings::get()`), so the path can't be changed from the admin and
the field renders read-only. You *can* type it into the settings screen instead
if you'd rather, but a constant keeps deployment config out of the database.

Leave the FTP settings empty. They're the alternative source, not a companion to
this one — a configured drop folder wins (`SM_Import_Local::configured()`).

What the folder needs to look like:

| Requirement | Why |
| --- | --- |
| `END.XML` written **after** the ZIP, as a separate file | It's the readiness signal. Until it appears the importer won't touch the archive. If Motorcentral doesn't send one, untick **Readiness** on the settings screen — see §2.4 |
| A `.zip` | The **newest by mtime** wins, ties broken by size. Unlike the FTP path (where LIST mtime formats are unreliable, so the largest wins), `filemtime()` is exact here |
| PHP able to delete `END.XML` | Consumed on pickup so the next drop is unambiguous. Best-effort — a read-only folder still works, since the fingerprint is the real idempotency guarantee |
| ~100 MB free on the `wp-content/uploads/` volume | The archive is copied into staging and extraction needs roughly 4× its size |

The vendor's archive is **copied, never moved or deleted** — a failed run leaves
the original in place to diagnose. If Motorcentral overwrites one fixed filename
each day, the folder never grows. If they use unique names, old archives
accumulate: they're inert (no sentinel, and the fingerprint has moved on) but
they do consume disk, so add a Plesk scheduled task to prune them. The
importer's own GC only cleans its staging directory, never the drop folder.

### 2.4 If the vendor doesn't send a loose END.XML

The sample package has `END.XML` *inside* the ZIP. Whether Motorcentral's export
also writes a loose copy beside it is the one thing to confirm with them — the
readiness check needs it.

If they don't send one, untick **Readiness** on the settings screen
(`require_sentinel`). The importer then treats the archive as ready once it has
stopped changing: at least `SM_Import_Local::MIN_AGE` (120 s) since the last
write, plus the 10-second size-stability recheck.

Reconciliation is unaffected either way. Its Guard 1 reads the `END.XML` *inside*
the archive, which the sample has, not the loose readiness file.

### 2.5 First run

On **Vehicles → Feed Import**:

1. **Test connection** — lists what's actually in the folder and reports whether
   it found the sentinel and which archive it would pick. Also surfaces a bad
   path, a permissions problem, or an `open_basedir` restriction.
2. **Run import now** with **Dry run** ticked — stages, extracts and parses,
   reports vehicle and photo counts, writes nothing.
3. **Run import now** for real.

The **Feed source** row at the top of the screen shows the resolved drop folder,
and names the problem if the path doesn't validate.

### 2.6 Working without a feed

With neither a drop folder nor an FTP host configured,
`SM_Import_Settings::fixture_path()` imports
`wp-content/uploads/sm-vehicle-import/fixtures/*.zip` so the pipeline can be
exercised end to end. It's a development shim, not a fallback: it skips the
sentinel and fingerprint checks, so a stale ZIP would re-import forever and sold
vehicles would never draft. The moment either real source is configured it's
ignored.

### 2.7 The FTP alternative

`class-sm-import-ftp.php` remains, for the case where the feed has to be pulled
from a remote server rather than dropped locally. Set `SM_FEED_FTP_HOST`,
`SM_FEED_FTP_USER`, `SM_FEED_FTP_PASS`, `SM_FEED_FTP_PATH` and
`SM_FEED_FTP_SCHEME` in `wp-config.php` and leave `SM_FEED_LOCAL_PATH` unset.
Notes if you go that way:

- Constants beat the options row, so the password stays out of the database and
  out of any SQL dump. **Don't type it into the settings screen instead**, and
  don't add a code path that persists it to options.
- `ftp` is plain FTP, passive. `ftps` is explicit AUTH TLS over `ftp://` with
  certificate verification **on** against WordPress's CA bundle — it needs a
  hostname with a valid public certificate, so a self-signed Plesk FTP cert will
  fail. `sftp` works but is username/password only (no keys).
- The largest `.zip` wins there, not the newest.

---

## 3. The feed package

Motorcentral's outer ZIP holds exactly three entries:

```
SML.XML            the vehicle data
SML-NN-Data.ZIP    nested ZIP of {StockNo}_{n}.jpg photos
END.XML            transfer-complete sentinel
```

Both archives are flat. Any entry name that isn't a plain filename
(`SM_Import_Package::SAFE_ENTRY`) is treated as hostile and aborts the run —
that's the zip-slip guard. Also enforced: at most 2000 entries, at most 500 MB
uncompressed, and a `ZipArchive::CHECKCONS` consistency check.

Data always comes from the **outer** `SML.XML`; the nested ZIP carries a
duplicate copy that is deliberately ignored.

`END.XML` plays two distinct roles, and it's worth keeping them apart:

- **Loose, in the drop folder** — the readiness signal. The importer waits for it
  before touching the archive, unless `require_sentinel` is off (§2.4).
- **Inside the archive** — recorded as the `sentinel` flag by
  `SM_Import_Package::extract()`, and that flag is Guard 1 of reconciliation.
  Without it vehicles are still updated, but nothing is drafted.

---

## 4. How a run works

`SM_Import_Runner::run()` → `execute()`, in this order:

```
1. obtain the feed        drop folder, FTP poll, fixture, or explicit zip_path
2. verify + extract       consistency, safe entries, size caps, unwrap images ZIP
3. parse                  SML.XML → normalised items, keyed by StockNo
4. validation gate        wrong dealer / no vehicles / malformed XML → abort
5. upsert                 create, update, or no-op each vehicle
6. queue photo work       sliced across cron events
7. reconcile              draft vehicles missing from the feed — guarded
```

Reconciliation is **last** and only runs after a clean parse. A truncated or
malformed feed therefore cannot empty the website: worst case, vehicle data is
stale and nothing is drafted.

Between steps 5 and 7 there is one more brake: if more than `max_skip_ratio`
(default 20%) of vehicles failed to import, the run aborts with
`sm_import_too_many_skipped` before reconciliation ever gets a chance.

### Source dispatch

`execute()` takes the first source that's configured:

```php
$ready = SM_Import_Local::configured()
    ? SM_Import_Local::poll($args['force'])
    : SM_Import_FTP::poll($args['force']);
```

…with `SM_Import_Settings::fixture_path()` ahead of both, but only when neither
is configured.

### Polling detail

`SM_Import_Local::poll()` is careful about half-written archives:

1. Validate the folder — absolute path, exists, readable — and warn if it sits
   inside the web root.
2. `scandir()` for plain files with their size and mtime.
3. No loose `END.XML` → stop, unless `require_sentinel` is off, in which case the
   archive must be at least 120 s old.
4. Fingerprint the newest archive as `md5(name|size|mtime)`; if it matches
   `sm_vehicle_import_last_fingerprint`, the feed is unchanged and the run stops.
5. Sleep 10 s, `clearstatcache()`, and compare the size again. A vendor that
   writes the sentinel early would otherwise let us read a partial file.
6. Copy into the staging directory, then verify the copy is byte-exact — a short
   copy means the file changed underneath us and must never reach reconciliation.
7. Store the fingerprint and delete the loose `END.XML`.

The FTP path, when that's the source instead, does the same dance over the
network:

1. `LIST` the directory (not `NLST` — size and mtime are needed).
2. No `END.XML` → log "feed is not ready" and stop.
3. Fingerprint the chosen archive as `md5(name|size|mtime)`; if it matches
   `sm_vehicle_import_last_fingerprint`, the feed is unchanged and the run stops.
4. Sleep 10 s, re-list, and compare sizes. A server that writes the sentinel
   early would otherwise let us read a partial file.
5. Stream to disk with `CURLOPT_FILE` (constant memory regardless of feed size),
   aborting if throughput stalls below 1 KB/s for a minute.
6. Compare bytes written against the LIST size — a short file is a truncated
   transfer and is deleted rather than imported.
7. Best-effort `DELE END.XML`.

cURL is used there rather than `ext/ftp` (often not compiled on shared hosts) or
`WP_Filesystem_FTPext` (buffers whole files into memory — a 23 MB archive as a
PHP string is not acceptable).

---

## 5. What lands in WordPress

Vehicles are `vehicle` posts keyed on the feed's `StockNo`, stored in
`_vehicle_stock_no`. Slugs are `{year}-{make}-{model}-{stock}`, set once on
insert and never re-derived — re-deriving would break live URLs.

### Feed-owned meta

Overwritten on every run (`SM_Import_Vehicles::FEED_META`):

`_vehicle_stock_no` `_vehicle_make` `_vehicle_model` `_vehicle_variant`
`_vehicle_variant_raw` `_vehicle_year` `_vehicle_odometer` `_vehicle_price`
`_vehicle_engine` `_vehicle_fuel` `_vehicle_body` `_vehicle_doors`
`_vehicle_colour` `_vehicle_rego` `_vehicle_vin` `_vehicle_chassis`
`_vehicle_condition` `_vehicle_location` `_vehicle_wof_expiry`
`_vehicle_rego_expiry`

Values are only written when they actually differ, so an unchanged feed touches
no rows.

### Bookkeeping meta

`SM_Import_Vehicles::CONTROL_META` — never shown as editable fields:

| Key | Purpose |
| --- | --- |
| `_vehicle_import_hash` | md5 of the whole payload (meta + title + content + features + photo signature). Equal hash → the vehicle is skipped entirely |
| `_vehicle_import_title` | the title the importer last wrote, to detect hand edits |
| `_vehicle_import_content_hash` | ditto for the description |
| `_vehicle_import_raw` | the raw `<Item>` as JSON, for debugging |
| `_vehicle_import_drafted_at` | set when the importer drafts a vehicle; its absence means a human drafted it |
| `_vehicle_weekly_auto` | the last auto-calculated weekly figure, to detect hand edits |

### Human-owned fields

`_vehicle_featured` and `_vehicle_sold` are curated in the admin and **never**
written by the importer (seeded empty on insert only, so the meta box renders
predictably).

### Taxonomies

- `vehicle_type` — the mapped body style, one term per vehicle. Public.
- `vehicle_feature` — the feed's `<Extras>`. Registered `public => false` and
  `publicly_queryable => false` on purpose: ~29 auto-generated thin-content term
  archives would be an SEO liability.

---

## 6. Field mapping

`class-sm-import-parser.php` is the single source of truth for every mapping.
The `sm/vehicle-filters` block builds its public dropdowns from live `DISTINCT`
meta with no allowlist of its own — so an unrecognised value must never be
written through to the database. It is discarded and logged as `UNMAPPED`
instead.

| Feed element | Becomes | Notes |
| --- | --- | --- |
| `StockNo` | `_vehicle_stock_no` | the identity key. Missing → item skipped. Duplicates → first wins |
| `Make` | `_vehicle_make` | title-cased, with a casing table for BMW, MG, LDV, GWM, BYD, Mercedes-Benz, Volkswagen, MINI, SsangYong … |
| `Model` | `_vehicle_model` | badge names, so casing is preserved for short tokens (XV, GLA), anything with a digit (A180, RAV4), and single-letter hyphenations (F-TYPE, I-PACE, CX-5) |
| `Variant` | `_vehicle_variant` (+ `_variant_raw`) | see below |
| `Type` | `_vehicle_body` + `vehicle_type` term | keyed off `<Type>`, **never** `<ItemType>`, which is dirty in the real feed (CONVERTABLE, HBK, one row reading "GO KARTS") |
| `FuelType` | `_vehicle_fuel` | `Other`/`Unknown` is sniffed out of the variant string (PHEV → Plug-In Hybrid before HYBRID → Hybrid, then E POWER, EV, BEV) |
| `Transmission` | `_vehicle_transmission` | the feed can only express A/M, so a CVT arrives as "Auto"; `\bCVT\b` in the variant wins |
| `Year` | `_vehicle_year` | rejected outside 1900 … current year + 2 |
| `Retail` | `_vehicle_price` | 0 → empty, and the vehicle displays as POA |
| `Mileage` | `_vehicle_odometer` | |
| `CCRating` | `_vehicle_engine` | 0 cc is how the feed represents an EV; written empty so the spec table doesn't read "0 cc" |
| `Doors` `Colour` `Rego` `VIN` `Chassis` `Location` | matching meta | rego uppercased and de-spaced |
| `NewUsed` | `_vehicle_condition` | U → Used, N → New |
| `WofExpiry` `RegoExpiry` | matching meta | `YYYYMMDD` → `Y-m-d`, impossible dates dropped |
| `OnlineNotes` | `post_content` | plain text; blank lines become paragraphs, single newlines `<br />`, then `wp_kses_post` |
| `Extras/Extra` | `vehicle_feature` terms | deduped and sorted |
| `Userdefined1` | `_vehicle_weekly` | 0.00 across the whole sample feed, but honoured if ever populated |
| `UserID` (document level) | — | must equal the `expected_user_id` setting (`SML`) or the run aborts, so another dealer's stock can never be imported |

### Variant, trim and titles

`Variant` arrives hard-truncated to 32 characters, roughly
`{TRIM} {DOORS} DOOR {CC} {BODY} {TRANS} {FUEL}`. `derive_trim()` cuts at the
last door count (the tail always restates it), or at the engine size, or at a
body keyword:

```
"AWD HYBRID 5 DOOR 2.0 RV-SUV AUT"  →  "AWD Hybrid"
"5 DOOR E POWER 5 DOOR 1.2 HATCHB"  →  "E Power"
"3.0 SUPERCHARGED 3.0 CONVERTIBLE"  →  "3.0 Supercharged"
"5 DOOR 1.6 HATCHBACK AUTO PETROL"  →  ""
```

Titles are `{Year} {Make} {Model} {Trim}`, falling back to the body style when
there's no usable trim. The raw variant is never used — it would produce
"2020 Nissan Note 5 Door E Power 5 Door 1.2 Hatchb".

### Weekly repayment

The feed carries no repayment figure, so it's amortised from the price using the
`weekly_*` settings (default 60 months, 12.95%, $395 fees, $0 deposit) and
stamped in `_vehicle_weekly_auto`. A real figure in the feed always wins.

Any advertised repayment must appear alongside the rate, term, deposit and fees
used to derive it — that's what the disclaimer in the `sm/vehicle-weekly` block
is for. Don't render the figure without it.

### Encoding

The feed declares no encoding, so libxml assumes UTF-8. Motorcentral is a
Windows product and will eventually emit a Windows-1252 byte (a macron, a degree
sign, a smart quote) that would fail the whole document — so invalid UTF-8 is
detected and converted, via mbstring or iconv, with a warning. `LIBXML_NOENT` is
deliberately absent and `LIBXML_NONET` is set: that's the XXE guard.

---

## 7. Photos

Photos are matched to vehicles by the `{StockNo}_{n}.jpg` convention, and
`_1` becomes the featured image (if there's no `_1`, the first photo is promoted
so a card is never blank). The rest are stored as an ID array in
`_vehicle_gallery`; the featured image is **not** repeated there, because the
gallery block prepends it itself. Ordering is numeric, not lexical — otherwise
`8958_10.jpg` would sort before `8958_2.jpg` and scramble every gallery.

Dedupe is filename + CRC32, read from the ZIP central directory without
decompressing anything:

- same key, same CRC → already in the library, skipped
- same key, different CRC → rephotographed; the new file is sideloaded and the
  old attachment deleted
- new key → sideloaded

Each vehicle's photo set is folded into its import hash as a signature, so a
photo-only change still counts as a change.

Because a 250-photo import can't run inside one request, work is queued in the
`sm_vehicle_import_queue` option and processed in ~20-second slices — by the
`sm_vehicle_import_process_images` cron event, or by an AJAX tick that drives the
progress bar during a manual run. The queue is persisted after every vehicle, so
a fatal error costs one job rather than the run. `media_handle_sideload()` moves
rather than copies, so the staging directory shrinks as work completes and a
crash can't double-import. The `1536x1536`, `2048x2048` and `medium_large`
subsizes are skipped (the gallery only requests `thumbnail` and `large`), which
removes roughly 40% of the image work.

If the staging directory disappears mid-queue (GC, a deploy, a disk clear) the
queue is abandoned and the fingerprint cleared, so the next scheduled run
re-downloads the feed.

---

## 8. What survives a re-import

Hand edits are respected — permanently — for anything a human might reasonably
want to fix:

| Field | Rule |
| --- | --- |
| Title | overwritten only while it still matches `_vehicle_import_title`. Edit it once and the feed stops touching it |
| Description | same, via `_vehicle_import_content_hash` |
| Transmission | a correction to `CVT` survives the feed's "Auto" |
| Weekly repayment | once the value differs from `_vehicle_weekly_auto`, it's left alone forever |
| Featured / Sold | never written by the importer at all |
| Draft status | a draft with no `_vehicle_import_drafted_at` was drafted by a human, and is left alone |

Conversely, re-importing an unchanged feed is a true no-op: no meta writes, no
`post_modified` bump, no image work. `wp_update_post()` is only called when a
post-table field genuinely changed, because it rewrites `post_modified`
unconditionally.

---

## 9. Reconciliation and its guards

When a vehicle sells it simply stops appearing in the feed. Reconciliation
drafts published vehicles that have disappeared — never deletes them — and
stamps `_vehicle_import_drafted_at`. If the vehicle reappears in a later feed it
is republished automatically.

Five guards must **all** pass or nothing is drafted:

| # | Guard | Default |
| --- | --- | --- |
| 1 | `END.XML` accompanied the feed | — |
| 2 | the feed contained at least one vehicle | — |
| 3 | the item count is at least `min_ratio` of the previous run's count | 50% |
| 4 | missing vehicles are within `max_draft_ratio` of the published total, floor of 3 | 30% |
| 5 | the vehicle carries a `_vehicle_stock_no` — hand-created vehicles are structurally invisible to reconciliation | — |

A skipped reconciliation is not silent: it logs a warning, sets
`skipped_reconcile` on the run, and shows an admin notice with a link to review
and override. **Reconcile now (override)** on the settings screen re-runs with
`force = true`, bypassing guards 1, 3 and 4. Guards 2 and 5 always hold.

`sm_vehicle_import_last_count` is what guard 3 compares against, and is only
updated on a successful reconciliation.

---

## 10. Scheduling, locking, logging

**Schedules** — `sm_every_15min`, `sm_hourly` (default), `sm_twice_daily`,
`sm_daily`, chosen on the settings screen. Changing the interval re-registers the
event immediately.

`sm_daily` is anchored: the first event is set to the next
`SM_Import_Runner::DAILY_HOUR` (5) o'clock in the site's timezone rather than
five minutes from now, and each run re-anchors the next one — a flat 86,400 s
interval would otherwise drift an hour across a daylight-saving change. Change
the hour by editing that constant.

Events:

| Hook | Job |
| --- | --- |
| `sm_vehicle_import_check` | poll and import |
| `sm_vehicle_import_process_images` | drain a slice of the photo queue, rescheduling itself while work remains |
| `sm_vehicle_import_gc` | daily: delete `run-*` staging directories older than 24 h and log files older than 30 days |

### Making the schedule actually fire

WordPress cron is not a clock — it only runs when someone visits the site, so on
a quiet site a "5am" event can land at 07:40 when the first visitor of the day
arrives. Drive it from a real cron job instead.

In `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

Then one Plesk scheduled task (Domains → **Scheduled Tasks** → Add Task), run
every 15 minutes:

```
*/15 * * * *   cd /var/www/vhosts/storermotors.co.nz/httpdocs && /opt/plesk/php/8.1/bin/php wp-cron.php >/dev/null 2>&1
```

Match the PHP binary to the domain's version — Plesk keeps them at
`/opt/plesk/php/<version>/bin/php`, and Domains → PHP Settings shows which is
active. Plesk's "Run a PHP script" task type does the same thing if you'd rather
not write the command by hand.

That is the whole setup. The 15-minute driver is what dispatches *whatever* is
due — the 5am timing comes from the `sm_daily` schedule inside WordPress, and the
same driver is what drains the photo queue in the slices after an import. A cron
that only fires at 5am would start the import and then leave the photos queued
until the next day.

Worst-case lateness is one driver interval, so `*/15` means the import starts by
05:15. Use `*/5` if that matters.

If WP-CLI is available, `wp cron event list` shows what is scheduled and when,
and `wp cron event run sm_vehicle_import_check` triggers a poll by hand.

**Pick the hour to suit the vendor, not the other way round.** Once a day means
one attempt: if Motorcentral's upload is still running at 05:00, or `END.XML`
isn't there yet, nothing imports until tomorrow. `sm_hourly` retries all day and
costs nothing when the feed hasn't changed — the fingerprint check short-circuits
before any download — so daily is only worth it if the vendor's drop time is
genuinely predictable.

**Locking** — a run holds the `sm_vehicle_import_lock` option, created with
`add_option()` because it is atomic (a `get`/`update` pair races). Stale locks
older than 30 minutes are cleared with a warning.

**Logging** — two layers, no custom table:

- `sm_vehicle_import_log` option: rolling summaries of the last 20 runs, which is
  what the admin screen renders.
- `wp-content/uploads/sm-vehicle-import/logs/sm-import-YYYY-MM-DD.log`: every
  line, at `INFO` / `WARNING` / `ERROR`. Kept 30 days.

Photo tallies are raised long after the run that queued them was summarised, so
they're held in a deferred bucket and folded back into the most recent run once
per slice — one option write per slice instead of one per photo.

**Failure email** — a failed run emails `admin_email`, rate-limited to once a day
so an unreachable FTP server can't flood the inbox.

---

## 11. Settings reference

Defaults live in `SM_Import_Settings::defaults()`; stored values live in the
`sm_vehicle_import_settings` option; `wp-config.php` constants override both for
the five FTP keys.

| Setting | Default | Notes |
| --- | --- | --- |
| `local_path` | empty | the drop folder. Constant `SM_FEED_LOCAL_PATH` wins and locks the admin field. Set, it takes precedence over every FTP setting |
| `require_sentinel` | on | wait for a loose `END.XML` before importing. Off → wait for the archive to be 120 s old and stable instead |
| `ftp_host` `ftp_user` `ftp_pass` `ftp_path` `ftp_scheme` | empty, `/`, `ftp` | only used when `local_path` is empty. Constants `SM_FEED_FTP_*` win, and lock the admin field |
| `expected_user_id` | `SML` | feed `UserID` must match or the run aborts |
| `schedule` / `schedule_enabled` | `sm_hourly` / on | `sm_every_15min`, `sm_hourly`, `sm_twice_daily`, `sm_daily` (anchored at `SM_Import_Runner::DAILY_HOUR`, 5am) |
| `min_ratio` | 0.5 | reconciliation guard 3 |
| `max_draft_ratio` | 0.30 | reconciliation guard 4 |
| `max_skip_ratio` | 0.20 | abort threshold before reconciliation |
| `weekly_enabled` | on | |
| `weekly_term_months` | 60 | |
| `weekly_rate` | 12.95 | annual %, converted to a weekly rate |
| `weekly_fees` | 395 | added to the principal |
| `weekly_deposit` | 0 | subtracted from the price |
| `show_vin` | off | **declared but not yet consumed** — VIN and chassis are imported, but nothing on the front end reads them |
| `purge_after_days` | 0 | **declared but not yet consumed** — intended as "purge drafted vehicles' images after N days"; 0 = never |

---

## 12. Troubleshooting

| Symptom | Cause | Fix |
| --- | --- | --- |
| Log repeats *"Feed is not ready — END.XML has not been written to the drop folder yet"* | no loose `END.XML` beside the archive | confirm how Motorcentral's export behaves; if they don't send one, untick **Readiness** (§2.4). **Test connection** shows exactly what's in the folder |
| *"No feed drop folder is configured"* | `SM_FEED_LOCAL_PATH` missing on this server | add the define to `wp-config.php` |
| *"…must be an absolute path"* / *"…does not exist or is not a directory"* | typo, or the vhost path isn't what you assumed | check it in Plesk's File Manager; Plesk names the directory after the subscription's primary domain |
| *"…is not readable by PHP"* | the FTP account maps to a different system user, or `open_basedir` excludes the path | create the FTP account under this subscription; or add the path under Domains → PHP Settings |
| *"Could not copy … into the staging directory"* | unreadable source, or no space in `wp-content/uploads/` | as above, and check the quota |
| Warning: *"drop folder … is inside the web root"* | the folder is under `httpdocs`, where nginx may serve it directly | move it to a sibling of `httpdocs` |
| `END.XML` keeps reappearing and re-importing | the vendor writes the sentinel but the archive is unchanged | harmless — the fingerprint stops the import; the log says "unchanged since the last run" |
| Runs finish with 0 items and no error | no drop folder, no FTP host, no fixture ZIP | configure `SM_FEED_LOCAL_PATH` |
| *"Feed archive is still being uploaded"* every run | the vendor writes the sentinel before the transfer completes, or the upload is slower than the poll interval | lengthen the interval, or ask the vendor to write `END.XML` last |
| Old archives piling up in the drop folder | the vendor uses unique filenames, and the importer copies rather than moves | add a Plesk scheduled task to prune them; they're inert, just not free |
| *"Feed UserID is X, expected SML"* | wrong dealer's export in the directory | fix the drop, or correct `expected_user_id` |
| Reconciliation keeps being skipped | a guard tripped — the warning names which | review the counts, then **Reconcile now (override)** if the feed is genuinely correct |
| `UNMAPPED body type "…"` warnings | the feed has a value the maps don't cover | extend the map in `class-sm-import-parser.php`; until then the value is discarded, not written |
| `REVIEW: description quotes $X but the feed price is $Y` | hand-written sales copy has gone stale | fix the copy in Motorcentral. Both figures appear on the page — a Fair Trading Act exposure, not a cosmetic one |
| Gallery order looks scrambled | photo filenames don't follow `{StockNo}_{n}.jpg` | check the vendor's export |
| *"An import is already running"* | a live run, or a lock left by a crash | wait 30 minutes and the stale lock clears itself |
| Import stalls with photos outstanding | cron isn't firing on a quiet site | hit the site, or drive `wp-cron.php` from a real cron job |
| *"Insufficient disk space"* | less than 4× the archive size free | clear `wp-content/uploads/sm-vehicle-import/run-*`, or raise the quota |

`wp-content/uploads/sm-vehicle-import/logs/` is the first place to look for any
of these.

---

## 13. Extending it

- **A new body/fuel/transmission value** — add it to the relevant `const` map in
  `class-sm-import-parser.php`. Don't add an allowlist anywhere else; the filter
  dropdowns read live `DISTINCT` meta and trust that whatever reached the
  database was mapped.
- **A new feed field** — map it in `map_item()`, add the meta key to
  `SM_Import_Vehicles::FEED_META` so it's owned by the feed, and register it in
  `register()` if the front end needs it.
- **A field humans must be able to correct** — follow the transmission or weekly
  pattern: record what the importer wrote, and stop overwriting once the stored
  value diverges.
- Stock numbers are numeric strings and PHP silently casts those to integers when
  used as array keys. Always cast back with `(string)` before comparing against a
  stock number from the database, or strict comparisons fail in ways that are
  painful to spot.

---

## 14. Current state (21 Aug 2026)

Not yet live. No source is configured, the fixture ZIP has been moved out of
`fixtures/` to `wp-content/uploads/vehicle-uploader/FTP Option.zip`, and the
schedule has been running as a no-op since the 13 Aug fixture run.

Remaining steps:

1. Create `orion_sync` and the vendor's restricted FTP account in Plesk (§2.1,
   §2.2).
2. Add `SM_FEED_LOCAL_PATH` to `wp-config.php` (§2.3).
3. `DISABLE_WP_CRON` plus the 15-minute Plesk scheduled task (§10), and pick the
   schedule on the settings screen.
4. Confirm with Motorcentral whether their export writes a loose `END.XML` beside
   the archive, or only the nested copy inside it (§2.4).

**The local drop-folder mode has not been exercised against a real feed yet** —
it was written after the FTP path and its first proving run will be the one on
the live server.
