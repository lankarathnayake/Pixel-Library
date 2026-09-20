# Pixel Library

A web-based file browser for your own images and videos. You point it at files or folders on the
computer it runs on; it stores **where each file is** (plus a little metadata) in a database - nothing is
uploaded or copied. You then organise the library with your own categories (Tags, Actors, Studios, ...) and
browse, search and filter it.

## What it does

- **Add files by location.** "Add files" opens a folder browser (Drives -> folders -> files). Tick files
  and/or folders, optionally include sub-folders, optionally tag everything you're adding, and it registers
  them. Adding the same file twice is harmless.
- **Library grid** with image thumbnails and video tiles, search (name *and* folder), type filter and sorting (incl.
  longest video). It stays fast on huge libraries: only a small window of the results is ever in the page - see
  "Big libraries" below.
- **Video previews.** ffmpeg pulls 5-10 frames from each video; hovering a video tile scrubs through them
  (left edge = start, right edge = end) with a time label. See "Video previews" below.
- **Video length filter, Playlists, Rescan folders, Duplicate finder** - see the sections of the same names below.
- **Actors page** (`actors.php`, "Actors" in the top bar): a page to search, sort, create and delete actors, with a profile for
  each - see "Actors" below.
- **Categories and terms.** Make any categories you like (starter: *Tags*, *Actors*). Each holds terms
  ("beach", "Jane Doe"). New terms are created on the fly when you type them.
- **Faceted filtering** in the sidebar: several terms in the same category = OR, across categories = AND.
  Plus *Untagged only* (great for working through a fresh import) and *Missing files only*.
- **Viewer** with previous/next (buttons or arrow keys), video playback with seeking, per-file tag editing
  (comma-separated entry, focus stays put for rapid tagging), copy-path button.
- **Select mode:** as soon as one file is ticked, clicking any other tile ticks / unticks it instead of opening it (shift-click
  = range, double-click still opens the file, Esc clears the selection and leaves the mode).
- **Bulk actions:** click the tick in a tile's corner (shift-click for a range), then apply or remove a
  term for everything selected, or remove the selection from the library. The name box takes **several names at once,
separated by commas**, and can mix categories: `beach, sunset, Actors: Jane Doe, Jane Roe` applies beach and sunset in
the category chosen in the bar and both actors in Actors (a `Category:` prefix switches category for the names after it).
"Remove" takes the same list. The viewer's side panel and the "Add files" dialog also take comma-separated names.
- **Missing-file check:** re-checks every registered file against the disk and flags the ones that moved.
  Files that reappear are un-flagged automatically.
- **Loading feedback.** Anything slow shows it: a thin bar across the top of the page for any request taking
  over ~0.25 s (quick ones never flash it), a dimmed grid with a "Loading..." pill while results reload, spinners
  on buttons that run long actions (which also ignore repeat clicks), a spinner over the folder list while a folder
  is read, and a spinner in the viewer until the image / video starts loading.
- **Two different "remove" actions, on purpose.** *Remove from library* only forgets the entry (the file stays on
  disk). *Delete from disk* **permanently deletes the file** - see "Deleting files" below. Deleting a category or
  term only removes the label.

Supported types: jpg, jpeg, png, gif, webp, bmp, avif, mp4, m4v, webm, ogv, mov, mkv, avi, wmv.
(Whether a video *plays* depends on your browser's codecs - e.g. mkv/avi/wmv often won't. The entry is still
catalogued and its path can be copied.)

## Requirements

- **Windows** (the folder picker, launcher and player buttons are written for it; the PHP code itself is portable).
- **Internet, once**, to fetch PHP (and, if you want video previews, ffmpeg) on the first start. Nothing else to install: no Apache,
  no MySQL, no XAMPP, no PHP setup.

## Install and run (standalone - no XAMPP, no database server)

1. **Get the app:** `git clone https://github.com/lankarathnayake/Pixel-Library.git`, or use GitHub's **Code -> Download ZIP** and
   unzip it into a folder you can write to (e.g. `D:\Apps\Pixel-Library` - not `Program Files`).
2. **Double-click `start.bat`.** That's all. It starts PHP's built-in web server on `http://127.0.0.1:8686/` and opens it in
   your browser. Leave its window open while you use the app; closing it stops the app.
   - **The very first time** there is no PHP yet, so `start.bat` downloads a portable one (about 30 MB, official build from
     windows.php.net, checked against its published SHA-256) into a `php` folder next to the app and carries on. Nothing is
     installed system-wide (no registry, no PATH change); delete the `php` folder to uninstall. Later starts take a second.
   - **Video previews** need ffmpeg, a separate program. If `start.bat` cannot find it, it asks whether to download it (about
     115 MB from gyan.dev, checked against its published checksum) into an `ffmpeg` folder next to the app. Answer **Y** (or wait
     20 seconds); **N** means "no previews, don't ask again" (delete `ffmpeg\declined.txt` to be asked again). Set
     `PIXEL_LIBRARY_FFMPEG=yes` to download without asking, or `no` to never offer it. Everything else works without ffmpeg.
   - Windows may show a "Windows protected your PC" notice for a script downloaded as a ZIP: choose *More info -> Run anyway*.
     `start.bat` is plain text - open it in Notepad to see everything it does.
   - No internet on that PC? Download "PHP 8.3, VS16 x64, Non Thread Safe (zip)" from https://windows.php.net/download/ on
     another machine and unzip it into a folder named `php` next to `start.bat`.
   - PHP needs the "Visual C++ 2015-2022 Redistributable (x64)", which most PCs already have
     (https://aka.ms/vs/17/release/vc_redist.x64.exe if it says a DLL is missing).
   - Already have PHP? Put `php.exe` on your PATH, or write its full path into a file named `php.path`. To keep PHP elsewhere:
     `powershell -ExecutionPolicy Bypass -File tools\get-php.ps1 -Dir D:\Tools\php` (also writes `php.path`).
3. Optional: copy `config.local.example.php` to `config.local.php` for your settings (ffmpeg path, time zone, ...).
   (Another port: set `PIXEL_LIBRARY_PORT` first. Don't want the browser to open: set `PIXEL_LIBRARY_NO_BROWSER=1`.)

**Updating:** pull / download the new version over the old one. Your library (`storage\`) and `php\` are not part of the
download, so they are kept, and a newer database layout is upgraded automatically on the next start. To update PHP itself
(security fixes), delete the `php` folder and start again: the newest 8.3 is fetched.

The library is one SQLite file, `storage/pixel-library.sqlite`, created on the first start and upgraded automatically
when a newer version needs new tables. **That file is your library - back it up by copying it** (with the app stopped, or
together with its `-wal` / `-shm` files if they exist). Thumbnails and actor photos are in `storage/`.

`start.bat` runs `php.exe -S 127.0.0.1:8686 router.php` with `php.standalone.ini`. `router.php` does what `.htaccess` does
under Apache: only pages, `api/` endpoints and `assets/` can be requested; everything else (code, config, the database,
this README) answers 404.

**One request at a time.** PHP's built-in server on Windows handles a single request at a time. In practice that is fine
for one person, and the app is built for it: video is sent in 8 MB slices (browsers ask for the next slice as it plays),
and the long jobs (previews, duplicate scan) run in 3-second slices. Very large jobs are best done with
`php bin\thumbs.php`, which is a separate process.

### Other ways to run it

- **Under Apache (XAMPP etc.):** put the folder in `htdocs` and browse to it - it works unchanged (`.htaccess` is included).
  It needs PHP's `pdo_sqlite` (enabled by default in XAMPP).
- **MySQL / MariaDB instead of SQLite:** create a database, load `db/schema.mysql.sql`, and set `DB_DRIVER`, `DB_HOST`,
  `DB_USER`, `DB_PASS`, `DB_NAME` in `config.local.php` (see `config.local.example.php`).
- **Moving an existing MySQL library to SQLite:** `php bin\migrate-mysql-to-sqlite.php` (read-only on MySQL; keeps every
  id, tag, playlist and preview; verifies row counts and the file's integrity). Add `--force` to rebuild an existing file.
  Options: `--mysql-host= --mysql-user= --mysql-pass= --mysql-db= --out=`.

**Thumbnails:** image thumbnails need PHP's GD extension (`php.standalone.ini` enables it). Without GD the grid loads the
original image files. They are cached in `storage/thumbs/` and regenerated if the source file changes.

## Video previews

ffmpeg is **not** part of the app (it has its own licence, GPL v3 for the build used here, and is large). `start.bat` offers to
download it on first start, or run `powershell -ExecutionPolicy Bypass -File tools\get-ffmpeg.ps1` yourself: it puts `ffmpeg.exe` and
`ffprobe.exe` into an `ffmpeg` folder next to the app (git-ignored; delete it to uninstall), and the app finds them there without
any setting. Already have ffmpeg? Put it on your PATH, or set `FFMPEG_PATH` / `FFPROBE_PATH` in `config.local.php` to the two
.exe files (these settings win over everything else) - `start.bat` then never asks.

- **What is made:** frames at the *middle of equal slices* of the video, so a 10-frame video is sampled at 5%, 15%,
  25% ... 95% (middles avoid black first/last frames). The count grows with length so short clips aren't
  over-sampled: under 1 min = 5 frames, under 5 min = 6, under 20 min = 8, longer = 10. Clips under 3 seconds get a
  single frame. Each frame is a 320 px wide JPEG (`VIDEO_THUMB_WIDTH`). The duration and dimensions are also read
  and stored, and the tile shows the duration. Frames are served with "revalidate" caching (a cheap 304 when
  unchanged), so regenerated previews show up immediately.
- **Where they go:** `storage/thumbs/video/<media id>/<n>.jpg`. The `media_thumb` table points at them (media id,
  order, time in the video, file name), and `thumb.php?id=N&n=I` serves a frame *by that DB row*.
- **When they are made:** not while adding (a big folder would take hours). Every new video is queued as
  *pending*; then either click **Generate previews** in the sidebar (shows progress, has a Stop button, tiles fill in
  live), or - better for thousands of videos - run the worker in a terminal:
  ```
  php bin/thumbs.php                 # everything pending; safe to Ctrl+C and re-run
  php bin/thumbs.php --limit=50      # just 50 videos
  php bin/thumbs.php --retry-failed  # also re-try videos that failed before
  ```
  **Just some videos:** tick them in the grid and press **Generate previews (N)** in the bulk bar (N = how many of
  the selected videos still need them; videos that already have previews are skipped). If every selected video
  already has previews the button reads **Regenerate previews** and asks before redoing them. The same works for one
  video: open it and use the **Generate / Regenerate previews** button in the side panel. A selection run uses the same
  progress panel and Stop button, and only touches the videos you selected. (API: `thumbs_queue` + `thumbs_run` with
  `ids`; up to 20,000 videos at a time.)
  Several frames of one video are extracted at once (`VIDEO_THUMB_PARALLEL`, default 4). Each video is *claimed*
  by exactly one worker, so the button and the terminal can run together, and a crashed worker's claim is retaken
  after 15 minutes.
- **Failures:** unreadable/corrupt videos are marked *failed* (the tile says so) and can be retried. A video whose
  file was replaced (size changed) is re-queued the next time it is added. A moved/deleted file is flagged missing.
- Removing a video from the library deletes its preview rows and files (never the video).

## Actors (profiles, ratings, photos, custom fields, talent lists)

An actor is an entry (tag) of the **Actors** category; the Actors page adds everything else. The category picker at the top
also lets you use the same page for any other category (Studios, ...).

- **The list:** search (name, full name, notes and custom fields), sort (name, rating, files, age, newest), filter by minimum
  rating / not rated / talent list; tick several to add or remove them from a list or delete them. Star ratings are clickable
  right in the list. Add one actor, or several at once separated by commas.
- **Profile** (click a name): photo, name (renames the tag), full name, date of birth (age is calculated), 1-5 star rating,
  notes, your custom fields, and which talent lists they are on. "Show the files" opens the library filtered to that actor;
  actor names in the viewer's side panel link to their profile.
- **Photo:** upload a JPG/PNG/WebP/GIF (max 5 MB) or drop it on the picture. The file's real content is checked, it is stored
  under a generated name in `storage/actors/` (git-ignored) and only served by `photo.php` from the database record. A new
  upload replaces (and deletes) the old one; deleting the actor deletes the photo. No photo = a coloured initial.
- **Photos beside names:** wherever the library shows an actor (the sidebar filter and the tag/actor chips in the viewer's
  side panel), an actor *with* a photo gets a small round photo next to the name. Hovering the photo enlarges it in a card
  with the name and full name (also on the Actors page). Actors without a photo just show their name.
- **Custom fields:** define text fields once ("Nationality", "Height", ...; single or multi-line) and every profile gets them.
- **Talent lists:** named collections ("Favourites", "To watch"); an actor can be on several. Filtering the library with
  `index.php?list=ID` shows everyone on a list's files.
- **Deleting an actor** removes the tag from every file plus their profile, photo, field values and list memberships. The
  files themselves are never touched.
- Tables added by `db/migrations/003-actor-profiles.mysql.sql` and `004-actor-photo.mysql.sql` (fresh installs already have them).

## Video length filter

Sidebar "Video length": Under 5 min, 5-10, 10-20, 20-30, 30-60, 1 hour or more, or **Custom...** (from / to in minutes).
A range includes its lower end and excludes its upper end (`5-10` = 5:00 up to, not including, 10:00). Only videos whose length
is known match - lengths are read when previews are generated. The sort menu has "Shortest video" next to "Longest video".

## Playlists (VLC / PotPlayer)

Tick files in the library -> **Add to playlist** in the bar at the top of the grid (choose a playlist or "New playlist...");
the viewer's side panel has the same for one file. The **Playlists** page (`playlists.php`) lists them, and for one playlist you
can drag / use the arrows to reorder, remove entries (the file stays in the library), sort (name, length, shuffle), rename, delete.

- **Download .m3u8** - a standard playlist (`#EXTM3U`, length + title + full path per file). Double-click it and Windows opens
  it in your default player. Files that are missing on disk are left out.
- **Play in PotPlayer / VLC** - saves the playlist to `PLAYLIST_DIR` (default `storage/playlists/`, git-ignored) and starts the
  player with it. Only players found on this PC are offered (VLC and PotPlayer in their usual install folders, plus `PLAYER_PATH`
  from `config.local.php` for anything else). The browser only ever sends the player's *name*, never a program path. It starts the
  player on the machine running the web server, so it works when that is your own PC; if the web server runs as a Windows service
  (Apache set up that way) the window may not appear on your desktop; with `start.bat` it always does - use the download instead.
- **Play now, no playlist needed** - tick videos in the library and press **Play in PotPlayer / VLC** in the bar (the viewer's
  side panel has it for one video). They play in the order you ticked them, through a single throw-away file
  `PLAYLIST_DIR/_now-playing.m3u8` that the next "Play now" simply overwrites - nothing is added to your saved playlists.
  Videos only; missing files are skipped.
- **Copy link** - `playlist.php?id=N` returns the live playlist, for VLC "Open Network Stream" / PotPlayer "Open URL"
  (works as long as the app is running).
- **Save to playlists folder** writes the `.m3u8` without opening anything.

## Rescan folders

Every folder you add through "Add files" is remembered. **Folders** (sidebar, Tools) lists them with how many files each has;
**Rescan** adds files that appeared since (new files only - nothing is ever removed or changed), **Rescan all** does every
folder, **Forget** stops remembering one (its files stay). Whether sub-folders are included is remembered per folder and
can be toggled. It also suggests folders that already hold most of your files but aren't remembered yet.

## Duplicates

`duplicates.php` (sidebar Tools -> "Find duplicates"):

- **Identical files** = same size *and* same fingerprint (SHA-1 of the size plus 1 MB from the start, middle and end; whole file
  when it is 3 MB or less). Only files that share a size are read at all, so scanning a big library is quick. "Scan" works in
  slices and shows progress; you can stop it and continue later. A file that can't be read is never reported as a duplicate.
- **Same length + resolution** is a hint list (probably the same video in another quality) - it is not proof.
- For each group you pick the one to **keep** (the highest resolution / largest / oldest is pre-selected), then either
  **Remove the others from the library** (files stay on disk) or **Delete the others from disk** (permanent, confirmed, logged
  like any deletion). "Move tags and playlist places to the kept file" (on by default) merges the copies' tags and playlist
  entries into the kept file first. "Keep suggested & delete the rest" does that for every group shown after typing DELETE.
- Nothing is removed unless the files are genuinely in one group with the file you keep.
- Tables/columns added by `db/migrations/005-folders-playlists-duplicates.mysql.sql` (fresh installs already have them).

## Big libraries: the sliding window (and the Display settings)

Only a limited number of tiles is ever in the page, however many files you have. Defaults: **50 per scroll, 100 at most**.

- Open the page: files 1-50. Scroll to the end: 51-100 load, so 1-100 are shown. Scroll to the end again: 101-150 load and
  1-50 are **removed**, so 51-150 are shown - and so on. Scrolling back **up** does the reverse: the previous rows reload
  and the ones at the bottom are dropped.
- **Two scrollbar modes** (Display dialog, "Scrollbar covers the whole list"):
  - **Off (default): the scrollbar covers only the loaded files.** The page is only as long as what is loaded (a few
    thousand pixels, not hundreds of thousands), so the scrollbar thumb stays large. Rows are added/dropped without the
    page jumping. Home / End load the real first / last files.
  - **On: the scrollbar covers the whole list.** Dropped rows are replaced by empty spacers of the same height (nothing
    is loaded there), so you can drag the scrollbar to any spot and the window is re-cut around it.
  In both modes windows start on a whole row, so tiles never reshuffle, and resizing the browser re-cuts the rows.
- A tile that is on screen is never dropped, so on a very tall screen the page briefly holds more than the maximum.
- **Selection is not lost:** files you ticked stay selected after their tiles leave the page (they come back ticked, and
  bulk actions apply to all of them). *Select all shown* ticks only what is on the page; *Clear* clears everything.
- The viewer (arrow keys) walks through the whole list, sliding the window as needed, and scrolls the grid back to the
  last-viewed file when closed.
- **Display** (button in the top bar) sets *Files loaded per scroll* (10-200) and *Most files shown at once* (at least
  2 x the per-scroll number, up to 1000). Saved in this browser; changing it reloads the list. On a very tall screen the
  page keeps at least enough tiles to fill the screen, even if your maximum is lower.
- API: `api/library.php?offset=ROW&limit=COUNT` (limit capped at 200 per request) alongside the older `page=`.

## Deleting files (permanent)

Tick files and press **Delete from disk** in the bulk bar, or open a file and use **Delete from disk** in its side
panel. This deletes the real file from your computer - it does **not** go to the Recycle Bin and can't be undone.

- **Confirmation:** a red dialog shows how many files, their total size and the first few names. For more than one
  file you must type `DELETE`; for a single file the focus starts on *Cancel*, and Enter/Esc never confirm.
- **What goes:** the file, its library entry, its tags (the tags themselves stay) and its preview thumbnails.
- **Safety checks, per file, on the server:** only files that are in the library can be deleted (by id, never a path);
  the file must still be exactly where it was added (a folder swapped for a link/junction is refused); it must be a
  regular file of a supported type; and inside `BROWSE_ROOTS` if you set it. A request without the explicit
  confirmation word or the CSRF token deletes nothing.
- **If it can't be deleted** (open in another program, read-only, no permission) the file *and* its entry/tags are
  kept and the message says which. Other files in the same batch are still deleted. A file that is already gone just
  has its entry cleaned up.
- **Log:** every deleted file is appended to `storage/deleted.log` (time, id, size, path; `DELETE_LOG` in the config)
  so there is a record of what was removed.
- *Remove from library* is the safe one: it only forgets the entry and never touches files.

## How it works

- The browser can't tell a web server a file's real path, so the "select file/folder" step is a **server-side
  folder browser** (`api/fs.php`): the PHP process lists folders on the machine it runs on. That is why the
  app is meant to run on the same computer you're using.
- `media` rows hold the absolute path, a hash of it (for de-duplication; case-insensitive on Windows), type,
  size, modified time and image dimensions.
- `file.php?id=N` streams a registered file (HTTP Range supported, so video seeks). It only serves files
  **that are in the library, looked up by id** - never an arbitrary path. `thumb.php?id=N` does the same for
  thumbnails.

```
index.php / manage.php     pages          assets/   CSS + JS (no framework, no build step)
api/                       JSON endpoints core/     PHP classes (all SQL lives here)
file.php  thumb.php        streaming      db/       schemas (sqlite + mysql) and migrations
common/                    bootstrap      tests/    backend tests
start.bat  router.php      standalone launcher + its router
tools/get-php.ps1          portable PHP downloader    tools/get-ffmpeg.ps1   ffmpeg downloader
bin/thumbs.php             CLI worker for video previews
bin/migrate-mysql-to-sqlite.php   one-off copy of a MySQL library into SQLite
```

## Security model - read this before exposing it

The app can list every folder on the machine and has **no login**. So by default it:

- only answers requests from `localhost` (IP *and* Host header checked - also blocks DNS-rebinding pages),
- requires a CSRF token on every state-changing request,
- only serves files that were registered through the picker,
- can be confined to specific folders with `BROWSE_ROOTS` in `config.local.php`.

Do not set `ALLOW_REMOTE` unless you have put your own authentication in front of it.

## Tests

```
php tests\run.php                              (SQLite: a throw-away temp file)
set TEST_DRIVER=mysql && php tests\run.php     (the same tests on MySQL: database pixel_library_test; user root without
                                               a password, or set TEST_MYSQL_HOST / TEST_MYSQL_USER / TEST_MYSQL_PASS)
```
Backend tests (adding, dedupe, search/filters, taxonomy, missing files, removal, video previews, permanent deletion, actors,
folders, duplicates, playlists, Unicode names). They create and drop their own throw-away database, a temp folder of dummy
files and a temp thumbnail folder - they never touch your real library, files or `storage/thumbs`. The video-preview tests
generate real videos with ffmpeg; if ffmpeg isn't on the PATH, point to it with the `FFMPEG_PATH` / `FFPROBE_PATH`
environment variables, otherwise that section is skipped.

## Database

Two engines behind one small layer (`core/Db.php`), chosen by `DB_DRIVER` (default `sqlite`). All SQL is in `core/` and is
plain SQL both understand; deletes are explicit rather than relying on cascades. Where they differ, `Db.php` handles it:

- **Names compare case-insensitively for any letter** (MySQL's `utf8mb4_unicode_ci` does). On SQLite the name columns use a
  `UNICODE_CI` collation registered on every connection - case-insensitive, sorted like MySQL (punctuation, digits, letters,
  emoji last) - and `LIKE` is replaced by a Unicode-aware version. Unlike MySQL, accents still tell names apart ("e" and
  "é" are different). Because those columns depend on it, other tools (sqlite3.exe, DB Browser) cannot sort or search them
  without registering a collation of that name.
- **A new SQLite file is created from `db/schema.sqlite.sql`** and upgraded by `db/migrations/sqlite/NNN-*.sql` files
  (numbered; the file's `PRAGMA user_version` says how far it is). Bump `Db::SCHEMA_VERSION` when adding one.
- **SQL rule for contributors:** a PDO parameter is text; SQLite only converts it to a number when it is compared with a
  column, not an expression - write `ROUND(x) = ROUND(?)`, not `ROUND(x) = ?`.
- SQLite runs in WAL mode with a 15-second busy timeout, so the web server and `bin/thumbs.php` can work at the same time.
## License

MIT - see `LICENSE`.
