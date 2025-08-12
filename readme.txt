=== Custom RSS Feeds (AS) ===
Contributors: alexandru-s
Tags: rss, feed, taxonomy
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Adds one or more RSS feeds to posts/pages/CPTs and taxonomy terms.
Automatically displays combined items before the footer (theme-agnostic),
with per-entity options and caching.

== Description ==
- Attach multiple RSS URLs per entity (post/page/product/category/tag/custom).
- Options per entity: limit, order, show image, show source, cache TTL.
- Automatic output at end of content (singles) and after the main loop (archives).
- WooCommerce support: single product + product archives hooks.
- Caching with manual refresh per entity. Clear error messages and minimal logging.

== Installation ==
1. Upload `as-rss` to `/wp-content/plugins/`.
2. Activate the plugin.
3. Edit a post/page/product or a category/tag and add feed URLs (one per line).

== Usage ==
- Singles: items appear near the bottom of the page.
- Archives: items appear below the list/grid.
- Change options and click **Refresh cache now** to force update.

== Screenshots ==
1. Post metabox
2. Category options
3. Frontend output

== Changelog ==
= 1.0.0 =
Initial release for coding test.

== Examples of tested feeds ==

- WordPress.org News — https://wordpress.org/news/feed/
- WooCommerce Blog — https://woocommerce.com/blog/feed/
- The Verge (has images) — https://www.theverge.com/rss/index.xml
- TechCrunch (has images) — https://techcrunch.com/feed/
- Smashing Magazine — https://www.smashingmagazine.com/feed/
- Ars Technica — https://arstechnica.com/feed/
- Planet WordPress — https://planet.wordpress.org/feed/
- NASA Image of the Day (image-heavy) — https://www.nasa.gov/rss/dyn/lg_image_of_the_day.rss
- Hacker News front page (mostly no images) — https://hnrss.org/frontpage

== Known Limitations ==
- Feed images depend on enclosures or first `<img>` in content.
- Some themes may require minor spacing tweaks.
