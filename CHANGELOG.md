# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-09-29

First release. Licensed under MIT.

### Added
- **Admin app** (WP-Admin → VideoOptimizer):
  - Videos: upload by drag & drop with presigned multipart uploads straight to storage, URL import, search, filters and live status.
  - Video details: poster from 10 frames, custom poster (upload or media library), player defaults, embed codes, delete.
  - Libraries: create, rename and delete, encoding ladder with add-on badges, reprocess.
  - Settings: the token is encrypted and write-only, with a connection test and support for the `VIDEOOPTIMIZER_API_TOKEN` constant. Also defaults, media library, WooCommerce and webhook settings.
- **Gutenberg blocks**: Video, Video + Text and Background Hero (both with inner blocks), Spotlight, Video Grid.
- **Shortcode** `[videooptimizer]` supporting every layout and attribute.
- **Elementor widget** with a video picker control, and delivery of optimized self-hosted Elementor videos.
- **Players**: VideoOptimizer player (iframe) and native HTML5 player (HLS natively or via on-demand hls.js, MP4 fallback). They can play in place (facade), in a lightbox or directly (lazy-injected once visible).
- **Media library integration**:
  - "Send to VideoOptimizer" from the attachment details, as a row action and as a bulk action.
  - Server-side URL transfer on public sites, browser upload everywhere else.
  - Optional auto-send of new uploads.
  - Delivery of optimized videos in `core/video`, `[video]` and Elementor.
- **WooCommerce** (HPOS and Cart/Checkout-blocks compatible):
  - Product gallery video slides that stay compatible with FlexSlider, zoom and PhotoSwipe.
  - A "Video" product tab.
  - Hover previews in classic and block-based product grids.
- **Deleting linked media library videos**:
  - The plugin then offers to delete the video in VideoOptimizer too. In the media grid this appears immediately, elsewhere as an admin notice.
  - The offer lists every place that still uses the video (blocks, shortcodes, Elementor, products, other media files).
  - The setting "When a linked media library video is deleted" accepts ask / keep / delete (the last only for unused videos).
- **WooCommerce variation videos**: a picker per variation. The video appears in the product gallery, and the gallery jumps to it when the variation is selected.
- **WooCommerce's own gallery videos** (classic gallery and the *Product Gallery* block): ready media library videos are served as the VideoOptimizer MP4 and switch to adaptive HLS once visible (hls.js, or native HLS on Apple devices). They use the VideoOptimizer poster when WooCommerce has none. The product meta box explains how this works with the block gallery.
- **Library encoding changes** ask whether to re-encode the existing videos of the library ("Save & re-encode" / "Only save").
- **Frontend**: hls.js is preferred wherever MediaSource exists and native HLS is used only on Apple devices, because Chromium's new native HLS failed on some streams. Native HLS falls back to the MP4 on errors.
- **Webhook receiver** for `video.ready` / `video.failed` (HMAC-SHA256, 5-minute replay window, delivery-id dedup), with a five-minute WP-Cron fallback.
- **SEO**: schema.org `VideoObject` JSON-LD.
- **WP-CLI**: `wp videooptimizer status|token|videos|send|sync|flush`.
- **German (formal) translation** for PHP and JS.
- **Quality tooling**: PHPUnit unit and integration tests, Jest, Playwright E2E, PHPCS (WPCS), PHPStan level 8, ESLint/Stylelint, Plugin Check, GitHub Actions CI.

[Unreleased]: https://github.com/ScaleCommerce/videooptimizer-wordpress/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/ScaleCommerce/videooptimizer-wordpress/releases/tag/v0.1.0
