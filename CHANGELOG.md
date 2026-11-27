# Changelog

All notable changes to this package. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Added
- Running uploads can be canceled in the video browser, and failed processing shows the API's error ([#11](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/11)).

### Changed
- Codecs and resolutions the organization has not unlocked (paid add-ons) are shown as locked and cannot be enabled; the library's `available_codecs` / `available_resolutions` decide ([#11](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/11)).
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
