=== Vivid - Sale Quantity Limiter for WooCommerce ===
Contributors: drickles1
Tags: woocommerce, sale, discount, inventory, stock
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cap how many units of a product sell at the WooCommerce sale price, then automatically revert to regular price once that many are gone.

== Description ==

WooCommerce lets you set a sale price and, optionally, a schedule for when it starts and ends. But there's no built-in way to say "I have 20 of this in stock, but I only want to discount the first 3 sold — after that, back to full price."

Without a quantity cap, a sale price with no end date just keeps selling at that price indefinitely, or until someone remembers to remove it by hand.

This plugin adds that missing piece: a **Sale Quantity Allocation** field. Set it to 3, and the moment 3 units have sold — from a real WooCommerce order, a stock reduction from an external inventory sync, a POS system, or a manual edit — the sale price is cleared automatically. The product reverts to its regular price for real (the underlying sale price field is cleared), not just a display trick.

= Features =

* Quantity-limited sales — set a max number of units to sell at the discounted price, independent of total stock.
* Source-agnostic tracking — works whether stock drops from a checkout, a manual wp-admin edit, or any external system updating stock via the REST API.
* "Only N left at this price!" badge shown automatically on the product page and shop loop while the allocation is still active.
* Manual re-arming only — once a batch sells out, the sale stays off until you explicitly restart it. Restocking alone does not silently reactivate a sale.
* Works on simple and variable products.

= How to run a limited sale =

1. Set a Sale price (and optionally a sale schedule) as you normally would in WooCommerce.
2. Enter a number in Sale Quantity Allocation (e.g. 3).
3. Tick "Start new limited-sale batch now".
4. Save/update the product.

From this point, the plugin watches the product's stock quantity. Every unit sold (by any means) counts against the allocation. When the allocation reaches 0, the sale price and any sale schedule dates are cleared automatically.

To restart a batch later (e.g. after restocking), re-enter the allocation number, tick the checkbox again, and save. This is deliberate: the allocation field is pre-filled with its last value and would otherwise resubmit on any unrelated product save, so the checkbox is what makes "manual re-arm only" actually manual.

= Source =

Development happens on GitHub: https://github.com/Drickles1/wc-limited-sale-quantity

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/vivid-sale-quantity-limiter/`, or install directly through the WordPress plugins screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Make sure WooCommerce is installed and active.
4. Edit any product and look for the new fields in the Pricing section of the General tab (or per-variation for variable products).

== Frequently Asked Questions ==

= Does this work with variable products? =

Yes. The allocation field and arm checkbox are available per-variation as well as on simple products. The "Only N left" frontend badge is proven for simple products; on variable products it reflects whichever variation WooCommerce's own template has in scope, since WooCommerce swaps variation data via AJAX without re-running this plugin's PHP per variation.

= What counts as a "sale"? =

Any WooCommerce stock reduction — a paid order, a manual wp-admin stock edit, or an external system (inventory management tools, POS systems, etc.) updating stock via the REST API — counts against the allocation.

= Why do I have to check a box to restart a sale instead of just re-entering the number? =

Because the allocation field is pre-filled with its current value, it resubmits on every product save regardless of whether you touched it. Requiring an explicit checkbox is the only way to guarantee a routine, unrelated edit (like fixing a typo in the description) can never silently reactivate a sale that already sold out.

= Can I set the allocation programmatically? =

The fields are wp-admin/UI only for now. The underlying values are plain post meta (`_lsqw_sale_qty_allocation`, `_lsqw_sale_qty_remaining`, `_lsqw_sale_qty_last_seen_stock`) and can be read or written directly if needed.

== Screenshots ==

1. The Sale Quantity Allocation field and "Start new limited-sale batch now" checkbox in the WooCommerce product Pricing tab.

== Changelog ==

= 1.2.1 =
* Renamed to Vivid - Sale Quantity Limiter for WooCommerce ahead of public release.

= 1.2.0 =
* Added a graceful admin notice (instead of a fatal error) if WooCommerce isn't active.
* Added the `Requires Plugins: woocommerce` header.

= 1.1.0 =
* Added an explicit "Start new limited-sale batch now" checkbox so a routine, unrelated product save can never silently re-arm a depleted allocation.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.1 =
Renamed from "Limited Sale Quantity for WooCommerce" for wordpress.org's plugin naming guidelines. No functional changes.
