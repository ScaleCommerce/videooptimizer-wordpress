# Changelog

All notable changes to this package. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Upgrade
- A video named by `?vo_video=` is shown only if it belongs to your organization (verified once with the API token, cached for an hour; "not found" for five minutes) and, when the detail element has a library, to that library; otherwise the page answers 404 without any video title. The verification is scoped by the API token and dropped when the video changes. New uuids and `vo_cursor` values from the URL share a site-wide budget of 30 uncached lookups a minute and never set the API-down marker; rate-limited, not-yet-ready and outage results are temporary ("temporarily unavailable", page flagged `DONOTCACHEPAGE`), only a verified 404 is a cacheable 404; temporary detail pages print `noindex` (core `wp_robots`, Yoast SEO, Rank Math). Videos listed by a video list count as verified for their detail page ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).
- Detail pages override the document title, meta description and canonical URL with the video's (`document_title_parts`, `get_canonical_url`; with Yoast SEO or Rank Math through their `wpseo_*` / `rank_math/*` filters, and no second meta description is printed); turn it off with the `videooptimizer_detail_meta` filter or by removing the filters ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).
- Elements print neutral public notices (`div.vo-notice.vo-notice--public`: "could not be found", "temporarily unavailable", list failures) for visitors; style or hide them in your theme ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).
- Video lists skip videos that are not ready and fill the page from up to three API calls; `vo_cursor` values are limited to 200 base64 characters ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).
- Pages with a video list or detail element are purged from page caches whenever any video changes; list pages are cached in transients for five minutes (generation counter option `videooptimizer_list_generation`), list and detail pages use the query arguments `vo_cursor`, `vo_video`. The match is a plain `video-list` / `video-detail` substring in content and Elementor data, so shared template parts, reusable blocks or widgets with such an element purge the whole site on every video change; `?vo_video=` variants are purged from WP Rocket, W3 Total Cache, SG Optimizer and LiteSpeed by URL ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).
- Scene previews load `https://api.videooptimizer.eu/api/v1/videos/{uuid}/thumbnails/{n}` (public, redirects to the CDN); allow it in a restrictive `img-src` Content-Security-Policy ([#21](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/21)).
- The mini player pins only after the visitor started playback (unmute, play, jump, scene click, or a click into a hosted player); autoplay and play-while-scrolling never pin, and opening a lightbox stops it. With a hosted player, keys typed inside the iframe do not reach the page, so Escape needs the close button there ([#24](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/24)).
- Poster images now carry an explicit `alt=""` (a second or third `loading` attribute on them comes from Elementor's or core's image filters, not from the plugin) ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).
- Directly shown videos with the mini player option are wrapped in `div.vo-mini > div.vo-mini__box`; the box is pinned with `position: fixed` (z-index 99990) while pinned, adjust custom CSS if needed ([#24](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/24)).
- Frames with a format get `vo-frame--fit`, `data-vo-ratio` and the `--vo-focus` custom property; `vo-frame--narrow` limits 9:16 and 4:5 to 420 px ([#22](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/22)).
- `POST /libraries/{id}/reprocess` answers 400 when `force` is present but not a boolean (it used to be treated as `false`) ([#25](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/25)).

### Added
- Format option for the video and video + text elements (block, shortcode `format`, Elementor): original, 16:9, 9:16, 1:1, 4:5, 21:9 with a focal point (`focalX`/`focalY`); native video and poster use `object-fit: cover`, the hosted player gets `fit=cover` ([#22](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/22)).
- Play while scrolling for direct players (video, video + text; `scrollPlay`): starts muted at 50 % visibility, pauses when it leaves, a visitor's pause sticks, no autoplay with `prefers-reduced-motion` or Save-Data, hls.js is attached on first play ([#23](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/23)).
- Floating mini player for directly shown videos (`miniPlayer`): once playing and scrolled out of view the player is pinned bottom right (CSS only, from 600 px) with a close button, Escape support and a height placeholder; native and hosted ([#24](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/24)).
- New element "Video with chapters" (block `videooptimizer/video-chapters`, shortcode `layout="video-chapters"`, Elementor layout): the video shown directly plus 3–10 scene previews (the video's thumbnails, start at `floor(duration / 11 * (i + 1))`, optional titles); a click starts the video at that scene (`currentTime` / hosted `t=…&autoplay=1`) ([#21](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/21)).
- New elements "Video list" (`videooptimizer/video-list`, `layout="video-list"`: cards of a library's ready videos, optional tags, cursor paging, detail page or lightbox) and "Video detail" (`videooptimizer/video-detail`, `layout="video-detail"`: the video named by `?vo_video=<uuid>` with title, player and JSON-LD; unknown uuid answers 404, page title, meta description and canonical from the video), also as Elementor layouts ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).
- Filter `videooptimizer_detail_meta` to turn off the detail page's meta description ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).

### Fixed
- Scene times use the exact (unrounded) video duration ([#21](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/21)).
- Pagination links keep other query arguments and work in subfolder installs ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).
- Library names and page titles in the Elementor selects are escaped ([#20](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/20)).
- The reprocess route rejects a non-boolean `force` with 400 ([#25](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/25)).
- The encoding quality field permanently says that existing videos need a reprocess with "Re-encode all qualities" ([#26](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/26)).

## [2.1.0] - 2026-11-28

### Upgrade
- When videos change (admin edit or webhook) the pages that show them are purged from WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, Speed Optimizer and the Cloudflare plugin if active, once per request and in a single call; whole-site purges (shared content, linked media library videos, token change) run at once, at most one per minute ([#19](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/19)).
- Pages with a processing video or any API failure except "not found" are flagged `DONOTCACHEPAGE` (cached for 60 s instead with LiteSpeed Cache); disable with the `videooptimizer_cache_degraded_pages` filter ([#19](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/19)).
- The theme's `controls: false` hides the native controls only for videos that autoplay; static (`interactive: false`) videos have no controls unless the block sets `controls` explicitly ([#15](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/15)).
- The lightbox is a native `<dialog class="vo-lightbox">` (shown with `showModal()`, `[open]` instead of `[data-open]`); update custom CSS or scripts that targeted the old `div` ([#18](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/18)).
- Background heroes contain a `button.vo-bg-hero__toggle`; hide it with CSS if a theme must not show it ([#18](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/18)).
- Videos with "Above the fold" print a `<link rel="preload" as="image">` for their poster next to the player; disable it with the `videooptimizer_preload_poster` filter ([#17](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/17)).
- Native players with subtitles get `crossorigin="anonymous"` and `<track>` children; cached embed data without tracks refreshes within an hour (or run `wp videooptimizer flush`) ([#16](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/16)).
- Native `<video>` elements may now carry `preload` from the player theme (above the fold), `controlslist`, `disablepictureinpicture` and the class `vo-native--static`; check custom CSS or markup filters ([#15](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/15)).

### Added
- Page cache purge for changed videos (found by searching content for the uuid), a whole-site purge when the API token changes, plus the action `videooptimizer_purge_video( $uuid, $post_ids, $all )` for custom setups (`$uuid` is '' for site-wide purges that concern no single video) and the filter `videooptimizer_shared_post_types` ([#19](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/19)).
- Background heroes have a pause/play button (WCAG 2.2.2); with `prefers-reduced-motion` they show the poster and start on request ([#18](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/18)).
- Above-the-fold posters are preloaded with `fetchpriority="high"` (matching `srcset`/`sizes` for facades) ([#17](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/17)).
- The native player renders the subtitle `tracks` of the embed as `<track>` elements (kind, srclang, label, default) ([#16](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/16)).

### Changed
- The lightbox is a modal `<dialog>`: focus stays inside, Escape closes it, the page behind is inert (set by hand without `showModal()`) ([#18](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/18)).
- JSON-LD `uploadDate` uses the embed's `uploadDate` when the API sends one ([#15](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/15)).
- Elementor video widgets opt out of Elementor's element cache ([#19](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/19)).
- The native player applies the theme's `preload` (`auto` capped at `metadata`), `interactive`, `fullscreenButton`, `playbackSpeed` and `pictureInPicture` ([#15](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/15)).

## [2.0.0] - 2026-11-27

### Upgrade
- Subtitles, tags, metadata and subtitle languages are written with the API token you already use: it needs `video:edit` (subtitles, tags, metadata) and `library:edit` (subtitle languages); without them the editor sees the API's message ([#8](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/8), [#9](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/9)).

### Added
- JavaScript tests for the frontend player: facade, lightbox, HLS wiring and fallbacks, deferred loading, reduced motion, heroes ([#14](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/14)).
- The Videos page shows the savings (storage reduction and estimated bandwidth saved, all-time and last 30 days; per library when one is selected) ([#10](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/10)).
- Plugin REST route `GET /videooptimizer/v1/savings` (cached for 5 minutes) ([#10](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/10)).
- Subtitles per video: list, upload (.vtt / .srt, replaces per language), download and delete, with kind (subtitles / captions) and default track ([#8](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/8)).
- Settings manage the organization's subtitle languages (add, remove; languages still in use are refused) ([#8](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/8)).
- Plugin REST routes `/subtitle-languages` and `/videos/{uuid}/subtitles[/{language}]`; changing subtitles drops the cached embed data ([#8](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/8)).
- Video detail shows and edits tags (with suggestions) and metadata key/values; URL import and upload accept them on the plugin REST routes ([#9](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/9)).
- The video browser filters by tag on the server; `GET /videooptimizer/v1/videos` accepts `tags` and `metadata[key]`, new route `GET /videooptimizer/v1/tags` ([#9](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/9)).
- Running uploads can be canceled in the video browser, and failed processing shows the API's error ([#11](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/11)).

### Changed
- Codecs and resolutions that are paid add-ons the organization has not unlocked carry an "Add-on not unlocked" badge ([#11](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/11)).
- The video browser loads 60 videos per page with "Load more" instead of every page on each reload, and keeps the loaded pages after uploads and edits ([#12](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/12)).
- After a transport error, rate limit or gateway error the plugin stops asking the API for uncached embeds for 60 seconds (cached videos are still served) ([#13](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/13)).

## [0.1.3] - 2026-11-27

### Upgrade
- Custom API base URLs (constant or filter) must be public hosts on port 80, 443 or 8080; allow others via the `http_request_host_is_external` and `http_allowed_safe_ports` filters ([#7](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/7)).

### Fixed
- MP4 fallback after an HLS error resumes playback for background heroes and scroll-autoload players ([#5](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/5)).
- Deleting, reprocessing or changing the ladder or quality of a library drops the cached embed data of its videos ([#6](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/6)).

### Security
- API calls no longer follow redirects (3xx is an error), reject unsafe URLs and refuse internal base URL hosts ([#7](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/7)).

## [0.1.2] - 2026-11-27

### Upgrade
- Embeds now load from `https://player.videooptimizer.eu`: allow it in your CSP `frame-src` and in consent tools (Borlabs, Complianz, ...), or define `VIDEOOPTIMIZER_EMBED_BASE_URL` as `https://videooptimizer.eu` to keep the old host ([#1](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/1)).

### Added
- Libraries show and set the encoding quality (`performance` | `quality`); after a change the plugin offers a forced re-encode of existing videos ([#2](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/2)).
- The plugin REST route `POST /videooptimizer/v1/libraries/{id}/reprocess` accepts `{"force": true}` ([#2](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/2)).
- URL import explains rejected video platform links (`source_url_video_platform`) and hints at the URL field to link a video file ([#3](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/3)).

### Changed
- App links point to `https://app.videooptimizer.eu` ([#1](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/1)).
- hls.js updated to 1.7.4 and cache-busted by its own content ([#4](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/4)).

### Fixed
- Library updates never send an empty codec or resolution list; the REST proxy rejects it ([#2](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/2)).
- API 403 messages such as trial locks are shown as sent; reprocess rate limits name the 5-per-hour limit ([#2](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/2)).
- Libraries no longer send the unknown `encoding_tier` field, which the API dropped silently ([#2](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/2)).

## [0.1.1] - 2026-11-27

### Changed
- API requests send a `User-Agent` with plugin, WordPress and PHP versions and site URL.

## [0.1.0] - 2026-09-29

First release.

### Added
- WP-Admin app: videos (multipart upload, URL import), poster, libraries with encoding ladder and reprocess, settings.
- Gutenberg blocks: Video, Video + Text, Background Hero, Spotlight, Video Grid.
- `[videooptimizer]` shortcode and Elementor widget.
- Players: VideoOptimizer iframe and native HTML5 (hls.js, MP4 fallback), as facade, lightbox or direct.
- Media library: send videos to VideoOptimizer (single, bulk, auto) and deliver optimized versions in `core/video`, `[video]` and Elementor.
- Ask to delete the VideoOptimizer video when a linked media library video is deleted; setting ask/keep/delete.
- WooCommerce: product gallery video slides, "Video" tab, hover previews, per-variation videos, optimized gallery videos.
- Library encoding changes offer to re-encode existing videos.
- Webhook receiver for `video.ready`/`video.failed` (HMAC-SHA256) with WP-Cron fallback.
- schema.org `VideoObject` JSON-LD.
- WP-CLI: `wp videooptimizer status|token|videos|send|sync|flush`.
- German translation.

### Security
- API token stored encrypted and write-only; `VIDEOOPTIMIZER_API_TOKEN` constant supported.
