=== VideoOptimizer ===
Contributors: scalecommerce
Tags: video, hls, woocommerce, product video, cdn
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Fast, adaptive video from a European CDN — Gutenberg blocks, shortcode, Elementor widget, media library integration and WooCommerce product videos.

== Description ==

VideoOptimizer delivers your videos the way streaming platforms do — adaptive (HLS), from a European CDN, in the right resolution for every device — without YouTube branding, ads or tracking.

This plugin connects WordPress and WooCommerce to your [VideoOptimizer](https://videooptimizer.eu) account.

= Highlights =

* **Eight blocks** for the block editor: Video, Video + Text, Background Hero, Spotlight, Video Grid, Video with Chapters, Video List and Video Detail — plus the `[videooptimizer]` shortcode and an **Elementor widget** with the same options.
* **Content elements**: chapters with scene previews, video lists with detail pages, every format (9:16, 1:1, 21:9 …) from one video, play while scrolling and a floating mini player.
* **Poster first**: until visitors click play, only a lightweight poster image is loaded (responsive `srcset`). Choose between the themable VideoOptimizer player and a native HTML5 player, playing in place, in a lightbox or directly.
* **Media library integration**: send existing videos to VideoOptimizer with one click (or in bulk). Video blocks, `[video]` shortcodes and Elementor video widgets are then delivered via VideoOptimizer automatically — no content changes.
* **WooCommerce**: product and variation videos in the product gallery (works with zoom and the lightbox; the gallery jumps to a variation's video when it is selected), a "Video" product tab and muted hover previews in shop and category grids.
* **WooCommerce's own gallery videos** (also in the new Product Gallery block) are delivered from VideoOptimizer with adaptive HLS once they have been sent and are ready.
* **Safe deletion**: when you delete a linked media file, the plugin asks whether to delete the video in VideoOptimizer too — and shows where it is still used.
* **Manage everything in WP-Admin**: upload (large files straight to storage, no PHP limits), import by URL, choose poster frames or upload custom posters, create libraries and configure the encoding ladder.
* **SEO**: schema.org `VideoObject` data for rich results.
* **Privacy & security**: the API token is stored encrypted and never reaches the browser. Webhooks are signed and verified.
* **Fast**: frontend assets load only on pages with a video; hls.js loads on demand and only in browsers without native HLS.

= Requirements =

* A VideoOptimizer account and an organization API token (Organization → API tokens).
* PHP 8.1+, WordPress 6.5+. WooCommerce 8.0+ and Elementor are optional.

== External services ==

This plugin connects to the VideoOptimizer service operated by ScaleCommerce GmbH (Germany) to manage and deliver your videos:

* **VideoOptimizer API** (`https://api.videooptimizer.eu`): used from your server when you browse, upload, edit or delete videos in WP-Admin, and to read the public playback data (sources, poster) of the videos you embed. Your API token is sent with admin requests. Every request identifies the plugin with its version, the WordPress and PHP versions and your site address (in the User-Agent header). Video files you upload are transferred directly from your browser to VideoOptimizer storage.
* **VideoOptimizer player and CDN** (`https://player.videooptimizer.eu`, `*.cdn.videooptimizer.eu`): visitors' browsers load posters, video files and — if you use the VideoOptimizer player — the player iframe from there.

Privacy policy: https://scale.sc/ueber-uns/datenschutz — Legal notice: https://scale.sc/ueber-uns/impressum

== Installation ==

1. Install and activate the plugin.
2. In VideoOptimizer, create an organization API token (Organization → API tokens).
3. In WP-Admin go to **VideoOptimizer → Settings**, paste the token and click **Save & test**.
4. Upload videos under **VideoOptimizer → Videos** — or send existing ones from the media library — and add them with the VideoOptimizer blocks.

Optionally pin the token in `wp-config.php`: `define( 'VIDEOOPTIMIZER_API_TOKEN', 'vp_…' );`

== Frequently Asked Questions ==

= Which player should I use? =

The **VideoOptimizer player** (iframe) uses the design you configure in your VideoOptimizer account and offers a quality selector. The **native player** is part of your page, styled by the browser, and gives the best one-tap experience on iOS. Both use adaptive HLS.

= Does it work on local or password-protected sites? =

Yes. Uploads always go directly from your browser to VideoOptimizer. For media library transfers the plugin uses the server only on publicly reachable https sites, otherwise the browser.

= Do I need webhooks? =

No. Without a webhook, WordPress checks every five minutes whether media library videos are ready. With a webhook (Organization → Webhooks in VideoOptimizer) the status updates instantly.

= What happens when I delete a media library video? =

WordPress deletes only the media file. The plugin then asks whether the optimized copy should be deleted in VideoOptimizer too, and lists pages and products that still use it. Under Settings → Media library you can instead always keep videos, or always delete unused ones.

= What happens when I deactivate the plugin? =

Videos embedded with VideoOptimizer blocks are no longer shown; media library videos fall back to the original files. Deleting the plugin removes its settings and metadata — your videos in VideoOptimizer are never touched.

== Screenshots ==

1. Videos in WP-Admin with upload, search and status.
2. Video details: poster frames, custom poster and embed codes.
3. VideoOptimizer blocks in the block editor.
4. Product video in the WooCommerce gallery.
5. Settings.

== Changelog ==

= 2.1.0 =
* Native player follows the player theme (preload capped at metadata, interactive, fullscreen, playback speed, Picture-in-Picture).
* Subtitle tracks of the video in the native player.
* Poster preload for above-the-fold videos.
* Pause/play button for background heroes, modal dialog lightbox.
* Cached pages are purged when a video changes (WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, Speed Optimizer, Cloudflare).

= 2.0.0 =
* Subtitles per video (upload, download, delete) and organization subtitle languages.
* Tags and metadata in the video detail, tag filter in the video browser.
* Savings on the Videos page.
* Video browser loads 60 videos per page; uploads can be cancelled; add-on encodings show as locked.
* Embeds fail fast for 60 seconds after API outages.
* API token needs `video:edit` and `library:edit` for the new editing features.

= 0.1.3 =
* Background heroes and scroll-autoload players keep playing after the MP4 fallback.
* Deleting or reprocessing a library refreshes the cached embed data of its videos.
* API calls don't follow redirects and refuse internal base URL hosts (custom base URLs: public host on port 80, 443 or 8080).

= 0.1.2 =
* Embeds load from https://player.videooptimizer.eu (allow it in CSP frame-src and consent tools, or define VIDEOOPTIMIZER_EMBED_BASE_URL).
* Libraries: encoding quality (performance or quality) and an optional forced re-encode.
* URL import explains rejected video platform links such as YouTube.
* hls.js 1.7.4.

= 0.1.1 =
* Requests to the VideoOptimizer API now identify the plugin, WordPress and PHP versions and the site address in the User-Agent header.

= 0.1.0 =
* First release.

== Third-party code ==

* hls.js 1.7 — Apache License 2.0 — https://github.com/video-dev/hls.js (loaded on demand in browsers without native HLS).
