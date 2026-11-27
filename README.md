<div align="center">

# VideoOptimizer for WordPress & WooCommerce

**Fast, adaptive video from a European CDN — blocks, shortcode, Elementor widget, media library integration and WooCommerce product videos.**

![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-3858e9)
![WooCommerce 8+](https://img.shields.io/badge/WooCommerce-8%2B-7f54b3)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![License MIT](https://img.shields.io/badge/license-MIT-green)

</div>

Sister projects: [videooptimizer-shopware](https://github.com/ScaleCommerce/videooptimizer-shopware) · [videooptimizer-sulu](https://github.com/ScaleCommerce/videooptimizer-sulu)

## Highlights

- **Five blocks**: Video, Video + Text, Background Hero, Spotlight, Video Grid, plus the `[videooptimizer]` shortcode and an **Elementor widget** with identical options and output.
- **Poster first**: visitors download nothing but a responsive poster until they press play. You can use the themable **VideoOptimizer player** (iframe) or a **native HTML5 player** (HLS natively in Safari/Chrome, hls.js on demand elsewhere). Either one can play in place, in a lightbox or directly.
- **Media library integration**:
  - "Send to VideoOptimizer" per video or in bulk.
  - Once a video is ready, `core/video` blocks, `[video]` shortcodes and Elementor video widgets are delivered via VideoOptimizer automatically. Your content stays unchanged.
  - After you delete a linked media file, the plugin asks whether to delete the video in VideoOptimizer too, and lists every page or product that still uses it. You can also set it to always keep, or to always delete unused videos.
- **WooCommerce**:
  - Product videos in the gallery. They work with FlexSlider, zoom and PhotoSwipe, and pause when you swipe away.
  - Videos per variation: the gallery jumps to the video when shoppers select that variation.
  - WooCommerce's own gallery videos (feature "Product gallery videos", classic gallery and the new *Product Gallery* block): once a media library video has been sent to VideoOptimizer and is ready, it is delivered from VideoOptimizer, with adaptive HLS and the VideoOptimizer poster.
  - A "Video" product tab.
  - Muted hover previews in shop and category grids.
- **Admin**:
  - Upload by drag & drop. Presigned multipart uploads go straight to storage, so no PHP limits apply.
  - Import by URL, pick poster frames or upload custom posters, edit player defaults.
  - Subtitles per video (.vtt / .srt) in the organization's subtitle languages, tags and metadata, and the storage and bandwidth savings.
  - Manage libraries and their encoding ladder. When you change the ladder, the plugin offers to re-encode the library's existing videos right away.
- **SEO**: schema.org `VideoObject` JSON-LD.
- **Security**:
  - The API token is encrypted at rest (libsodium, key derived from the WP salts) and is never sent to the browser.
  - Every REST route is capability-checked and uses an allowlist.
  - Webhooks are verified with HMAC and replay protection.
- **Performance**: assets load only on pages that contain a video, the hosted player is lazily injected, and embed lookups are cached (transients) with a 3-second timeout, so a slow API never slows a page.
- **Translation-ready**, with German (formal) bundled. The plugin passes wordpress.org Plugin Check.

## Installation

1. Download `videooptimizer.zip` from the [latest release](https://github.com/ScaleCommerce/videooptimizer-wordpress/releases), or build it yourself (see *Development*).
2. In WP-Admin, go to **Plugins → Add New → Upload Plugin**, upload the ZIP and activate it.
3. Continue with **VideoOptimizer → Settings** (see below).

Requirements: PHP 8.1+ with sodium, WordPress 6.5+. WooCommerce 8.0+ and Elementor are optional.

## Using the plugin

1. **VideoOptimizer → Settings**: paste an organization API token (VideoOptimizer app → Organization → API tokens), then click *Save & test*. Pick a default library, player and presentation. Alternatively, put `define( 'VIDEOOPTIMIZER_API_TOKEN', 'vp_…' );` in `wp-config.php`.
2. **VideoOptimizer → Videos**: upload or import videos, then click one to change its poster, player defaults or copy its embed code.
3. **Editors**:
   - **Block editor**: insert one of the *VideoOptimizer* blocks. *Video + Text* and *Background Hero* use regular inner blocks for their text.
   - **Shortcode**: `[videooptimizer uuid="…"]`. Every block attribute works as a shortcode attribute, for example `layout="media-split" player="native" presentation="lightbox" autoplay="1" cta_url="/shop"`.
   - **Elementor**: use the *VideoOptimizer* widget.
4. **Media library**: *Send to VideoOptimizer* is available as a row action, bulk action and in the attachment details. On public https sites the server hands over the file URL. Locally or behind a password, the browser uploads the file instead.
5. **WooCommerce**: use the *Product videos (VideoOptimizer)* box on the product edit screen for gallery videos, the tab video and the hover preview. Each variation has an additional *Variation video* field.
6. **Webhook (optional)**: in the VideoOptimizer app, create a webhook for `video.ready` and `video.failed` that points to `https://your-site/wp-json/videooptimizer/v1/webhook`, and paste its secret into the plugin settings. Without a webhook, WP-Cron checks processing videos every 5 minutes.

### Configuration reference

| Setting | Default | Notes |
|---|---|---|
| Library for new uploads | first media-managed library | used for media library transfers |
| Default player | VideoOptimizer player | `hosted` (iframe) or `native` |
| Default presentation | Poster, plays in place | `facade`, `lightbox`, `direct` |
| Deliver optimized media library videos | on | core/video, `[video]`, Elementor video |
| Send new uploads automatically | off | server-side on public sites, otherwise queued for the browser |
| Transfer method | automatic | force browser uploads for protected sites |
| When a linked media file is deleted | ask | `ask`, `keep`, or `delete` (only if unused). Videos still in use are never deleted without asking. |
| WooCommerce gallery / position / tab / hover | on / after images / on / on | |
| schema.org VideoObject | on | filter `videooptimizer_schema` |

**Constants**:
- `VIDEOOPTIMIZER_API_TOKEN`
- `VIDEOOPTIMIZER_API_BASE_URL`, `VIDEOOPTIMIZER_EMBED_BASE_URL` (staging)

**Filters**:
- `videooptimizer_capability`
- `videooptimizer_api_base_url`, `videooptimizer_embed_base_url`
- `videooptimizer_schema`
- `videooptimizer_core_video_options`
- `videooptimizer_enqueue_early`
- `videooptimizer_preload_poster`
- `videooptimizer_cache_degraded_pages`, `videooptimizer_shared_post_types`

**Actions**: `videooptimizer_webhook`, `videooptimizer_purge_video`.

**Page caches**: when videos change, the published posts that contain their uuid (blocks, shortcodes, Elementor data, WooCommerce product and variation videos) are purged in WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Super Cache, Speed Optimizer (SiteGround) and the Cloudflare plugin, once at the end of the request and in a single call. The whole site is purged for videos in template parts, reusable blocks or widgets, videos linked to a media library item (core/video, `[video]`, galleries and media widgets reference the attachment, not the uuid), videos on more than 100 pages, more than 20 changed videos at once, and when the API token changes. A whole-site purge runs at once, at most one per minute; others within that minute share one follow-up (WP-Cron; with `DISABLE_WP_CRON` run it from a system cron). LiteSpeed Cache queues purges made during cron or after output and sends them with the next response. Nothing is searched if no page cache is active; hook `videooptimizer_purge_video( $uuid, $post_ids, $all )` to purge a custom cache or CDN. Pages with a processing video or any API failure except "not found" (temporary errors, rate limit, token or permission problems) are sent to page caches as uncacheable (`DONOTCACHEPAGE`, plus a 60 s TTL for LiteSpeed Cache); deleted videos stay cacheable.

**WP-CLI**:
- `wp videooptimizer status`
- `wp videooptimizer token set <token>` / `wp videooptimizer token delete`
- `wp videooptimizer videos`
- `wp videooptimizer send <attachment-id>…`
- `wp videooptimizer sync`
- `wp videooptimizer flush [<uuid>]`

### Player theme in the native player

The theme of the organization/library/video (VideoOptimizer app) is applied to the native player as far as the browser's own controls allow. Block attributes override it.

| Theme key | Native player |
|---|---|
| `controls` | `controls` attribute; `false` only applies to videos that autoplay (muted), otherwise the visitor could not start them |
| `autoplay`, `mutedStart` | autoplay (always muted) / muted start |
| `loopButton` | `loop` |
| `preload` | `none` stays `none`; `auto` and `metadata` become `metadata` for above-the-fold videos (native players never buffer segments before playback); deferred videos never preload. Only a prioritised background hero buffers eagerly |
| `interactive: false` | `pointer-events: none`, no controls (unless the block sets `controls` explicitly), only for videos that autoplay directly |
| `fullscreenButton`, `playbackSpeed` | `controlslist="nofullscreen noplaybackrate"` |
| `pictureInPicture` | `disablepictureinpicture` |
| `accentColor` | `--vo-player-accent` (controls, focus ring) |

Subtitles uploaded in VideoOptimizer appear as `<track>` elements (the video gets `crossorigin="anonymous"`); the viewer picks them in the browser's subtitle menu, the default track starts shown.

Other keys (`controlBarBg`, `playButton*`, `bigPlayButton`, `qualitySelector`, `volumeControl`, `timeDisplay`) have no native equivalent and are ignored; use the VideoOptimizer player for them.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| "VideoOptimizer is not connected yet" | No token, or the WP salts changed. Enter the token again. |
| 401 / "token is invalid" | The token was revoked or has expired. Create a new **organization** token. |
| 403 "plan limit" | The trial allows 1 library and 20 videos. Upgrade, or delete unused videos. |
| Uploads/poster disabled for a library | The library is delivery-only (`media_managed: false`). |
| A video shows the original file | It is still processing. Webhook or cron will pick it up, or run `wp videooptimizer sync`. |
| A video is missing on the page | Deleted in VideoOptimizer. Editors see a notice, visitors see nothing. |
| Media library transfer fails on a protected site | Set *Transfer method* to *Always upload from the browser*. |

## How it works

- `src/Api/Client.php`: the server-side API client (`wp_remote_request`):
  - bearer token, cursor pagination (at most 100 pages, stops if a cursor repeats)
  - one retry on 429 after at most 5 s
  - translated error messages
- `src/Rest/AdminController.php`: REST proxy at `/wp-json/videooptimizer/v1/*` used by the admin app, the block editor, Elementor and the media library.
- `src/Render/*`:
  - `EmbedRepository` caches the public `GET /embed/{uuid}`: 1 h when ready, 60 s while processing or after an error.
  - `Renderer` produces the markup for every editor.
  - `Assets` enqueues assets only where needed.
- `src/Media/*`: attachment↔video mapping, server-side URL transfer, and a cron fallback that polls status.
- `src/Woo/*`: meta box (product CRUD, HPOS-safe), gallery slides, product tab, hover previews.
- `src/Editor/*`: blocks, shortcode, core/video replacement, Elementor.
- `assets/src/*`: React UIs (`@wordpress/components`) and the vanilla frontend script. Built with `@wordpress/scripts`.

## Development

```bash
composer install          # PHP dev tools (PHPUnit, PHPCS/WPCS, PHPStan)
npm ci                    # build tooling (@wordpress/scripts, Playwright)
npm run build             # or: npm run start (watch)
```

| Command | What it does |
|---|---|
| `composer test` | PHP unit tests (Brain Monkey, no WordPress needed) |
| `bash tests/bin/run-integration.sh` | Integration tests against a real WordPress + WooCommerce. Needs a WordPress install and a test database; see `tests/wp-tests-config.php` for the environment variables. |
| `npm run test:unit` | Jest tests |
| `npm run test:e2e` | Playwright smoke tests. Expect sites with demo content at `WP_URL` / `WOO_URL`. |
| `composer lint` / `composer fix` | PHPCS (WordPress Coding Standards) + PHPStan level 8 |
| `npm run lint` / `npm run lint:fix` | ESLint + Stylelint |
| `bash bin/i18n.sh` | Regenerate the POT, update `de_DE`, compile `.mo` + JS `.json` (needs WP-CLI) |
| `bash bin/zip.sh` | Release ZIP → `dist/videooptimizer.zip` (honours `.distignore`) |

`readme.txt` is the wordpress.org readme. CI (GitHub Actions) runs the unit tests on PHP 8.1–8.4, runs lint, builds the plugin and runs Plugin Check. On `v*` tags it attaches the ZIP to a GitHub release.

## License

MIT © ScaleCommerce, see [`LICENSE`](LICENSE). Bundled third-party code is listed in [`THIRD-PARTY-NOTICES.txt`](THIRD-PARTY-NOTICES.txt) (hls.js, Apache-2.0).
