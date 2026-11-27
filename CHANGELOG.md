# Changelog

All notable changes to this package. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Upgrade
- When videos change (admin edit or webhook) the pages that show them are purged from WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, Speed Optimizer and the Cloudflare plugin if active, once per request and in a single call; whole-site purges (shared content, linked media library videos, token change) run at once, at most one per minute ([#19](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/19)).
- Pages with a processing video or any API failure except "not found" are flagged `DONOTCACHEPAGE` (plus a 60 s TTL with LiteSpeed Cache); disable with the `videooptimizer_cache_degraded_pages` filter ([#19](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/19)).
- The theme's `controls: false` hides the native controls only for videos that autoplay; static (`interactive: false`) videos have no controls unless the block sets `controls` explicitly ([#15](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/15)).
- The lightbox is a native `<dialog class="vo-lightbox">` (shown with `showModal()`, `[open]` instead of `[data-open]`); update custom CSS or scripts that targeted the old `div` ([#18](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/18)).
- Background heroes contain a `button.vo-bg-hero__toggle`; hide it with CSS if a theme must not show it ([#18](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/18)).
- Videos with "Above the fold" print a `<link rel="preload" as="image">` for their poster next to the player; disable it with the `videooptimizer_preload_poster` filter ([#17](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/17)).
- Native players with subtitles get `crossorigin="anonymous"` and `<track>` children; cached embed data without tracks refreshes within an hour (or run `wp videooptimizer flush`) ([#16](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/16)).
- Native `<video>` elements may now carry `preload` from the player theme (above the fold), `controlslist`, `disablepictureinpicture` and the class `vo-native--static`; check custom CSS or markup filters ([#15](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/15)).

### Added
- Page cache purge for changed videos (found by searching content for the uuid), a whole-site purge when the API token changes, plus the action `videooptimizer_purge_video( $uuid, $post_ids, $all )` for custom setups and the filter `videooptimizer_shared_post_types` ([#19](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/19)).
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
