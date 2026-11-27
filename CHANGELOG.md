# Changelog

All notable changes to this package. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Added
- Libraries show and set the encoding quality (`performance` | `quality`); changing it re-encodes existing videos with `force` ([#2](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/2)).
- `POST /libraries/{id}/reprocess` accepts `{"force": true}` ([#2](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/2)).

- URL import explains rejected video platform links (`source_url_video_platform`) and hints at the URL field to link a video file ([#3](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/3)).

### Changed
- Default player host is `https://player.videooptimizer.eu` (was `videooptimizer.eu`); `VIDEOOPTIMIZER_EMBED_BASE_URL` and the `videooptimizer_embed_base_url` filter still override it, old embed URLs keep working ([#1](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/1)).
- App links point to `https://app.videooptimizer.eu` ([#1](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/1)).
- hls.js updated to 1.7.4 ([#4](https://github.com/ScaleCommerce/videooptimizer-wordpress/issues/4)).

### Fixed
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
