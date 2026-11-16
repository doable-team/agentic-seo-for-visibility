#!/bin/bash
# T7 / #289 WooCommerce + Yoast: product name/descriptions/slug changed through
# WooCommerce's own save, product SEO through the SEO adapter, product category
# text + SEO; price, stock, SKU, tax, shipping and variation data refused with
# nothing written; rollback; only the product's cache cleared (WP Rocket
# stand-in). Needs the real WooCommerce and Yoast SEO active on site S.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-1}
code() { echo "$1" | jget "d.get('errorCode')"; }
$CTR cp standins/wp-rocket-standin.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/wp-rocket-standin.php
$WP $S option delete vis_test_purges >/dev/null 2>&1

P=$($WP $S eval '$p = new WC_Product_Simple(); $p->set_name("T7 Mug '$RANDOM'"); $p->set_status("publish"); $p->set_regular_price("19.99"); $p->set_sku("T7-'$RANDOM'"); $p->set_manage_stock(true); $p->set_stock_quantity(7); $p->set_description("<p>Old long text</p><p>Second para</p>"); $p->set_short_description("Old short"); echo $p->save();' | tr -d '\r' | tail -1)
O=$($WP $S eval '$p = new WC_Product_Simple(); $p->set_name("T7 Other"); $p->set_status("publish"); $p->set_regular_price("5"); echo $p->save();' | tr -d '\r' | tail -1)
CAT=$($WP $S term create product_cat "T7 Mugs $RANDOM" --description="Old category text" --porcelain | tr -d '\r' | tail -1)
$WP $S eval "wp_set_object_terms($P, [$CAT], 'product_cat');" >/dev/null
SKU=$($WP $S eval "echo wc_get_product($P)->get_sku();")
$WP $S option delete vis_test_purges >/dev/null 2>&1  # creating the products purged them too

C=$(api $S GET /capabilities)
check "$(echo "$C" | jget "d['woocommerce']['writable']")" "True" "capabilities: woocommerce writable"
check "$(echo "$C" | jget "d['woocommerce']['productFields']")" "['name', 'description', 'shortDescription', 'slug']" "capabilities: product fields"
check "$(echo "$C" | jget "list(t['writable'] for t in d['postTypes'] if t['name']=='product')")" "[True]" "capabilities: product type writable"
check "$(echo "$C" | jget "'woocommerce.products' in d['features']")" "True" "features: woocommerce.products"
check "$(api $S GET "/posts?type=product&per_page=100" | jget "$P in [p['id'] for p in d['items']]")" "True" "list type=product"

R=$(api $S GET /posts/$P)
check "$(echo "$R" | jget "d['product']['readOnly']['price']")" "19.99" "read: price shown read-only"
check "$(echo "$R" | jget "d['product']['shortDescription']")" "Old short" "read: short description"
check "$(echo "$R" | jget "d['product']['categories'][0]['id']")" "$CAT" "read: product category"
check "$(echo "$R" | jget "d['seo']['plugin']")" "yoast" "read: SEO through Yoast"
M=$(echo "$R" | jget "d['modifiedGmt']")

# Price / stock / SKU refused, nothing written.
for k in price regular_price regularPrice sale_price stock_quantity stock_status sku tax_class shipping_class weight variations; do
  R=$(action $S product.update "{\"productId\":$P,\"fields\":{\"description\":\"<p>x</p>\",\"$k\":\"1\"}}")
  check "$(code "$R")" "not_allowed" "product.update with $k refused"
done
R=$(action $S post.update_content "{\"postId\":$P,\"fields\":{\"title\":\"x\",\"_regular_price\":\"1\"}}")
check "$(code "$R")" "not_allowed" "post.update_content with _regular_price on a product refused"
R=$(action $S fields.update "{\"postId\":$P,\"fields\":{\"meta:_price\":\"1\"}}")
check "$(code "$R")" "not_allowed" "fields.update meta:_price refused"
check "$($WP $S eval "\$p=wc_get_product($P); echo \$p->get_regular_price().'|'.\$p->get_price().'|'.\$p->get_stock_quantity().'|'.\$p->get_sku().'|'.\$p->get_description();")" "19.99|19.99|7|$SKU|<p>Old long text</p><p>Second para</p>" "price, stock, SKU, description unchanged after refusals"

# Words changed through WooCommerce.
R=$(action $S product.update "{\"productId\":$P,\"modifiedGmt\":\"$M\",\"fields\":{\"description\":\"<p>New long text</p><p>Second para</p>\",\"shortDescription\":\"New short <strong>bold</strong>\",\"name\":\"T7 Better Mug\"}}")
check "$(echo "$R" | jget "d['ok']")" "True" "product.update ok"
check "$(echo "$R" | jget "d['result']['after']['shortDescription']")" "New short <strong>bold</strong>" "result: after"
check "$(echo "$R" | jget "d['result']['cachePurged']")" "['WP Rocket']" "result: cache cleared (WP Rocket stand-in)"
CH=$(echo "$R" | jget "d['result']['changeId']")
check "$($WP $S eval "\$p=wc_get_product($P); echo \$p->get_regular_price().'|'.\$p->get_stock_quantity().'|'.\$p->get_name();")" "19.99|7|T7 Better Mug" "price + stock untouched, name changed"
check "$($WP $S option get vis_test_purges --format=json | jget "sorted(set(x['post'] for x in d))")" "[$P]" "only the product's cache purged (not $O)"
PAGE=$(curl -sL "$($WP $S post url $P | tr -d '\r')")
check "$(echo "$PAGE" | grep -q 'New long text' && echo yes)" "yes" "front end: new description"
check "$(echo "$PAGE" | grep -q 'New short' && echo yes)" "yes" "front end: new short description (block theme renders it in its product block)"
R=$(action $S product.update "{\"productId\":$P,\"modifiedGmt\":\"$M\",\"fields\":{\"name\":\"stale\"}}")
check "$(code "$R")" "conflict" "stale modifiedGmt refused"
# Rollback through the plugin's saved copy, then change it again.
R=$(api $S POST /changes/$CH/rollback)
check "$(echo "$R" | jget "d['ok']")" "True" "rollback ok"
check "$($WP $S eval "\$p=wc_get_product($P); echo \$p->get_short_description().'|'.\$p->get_description().'|'.\$p->get_regular_price().'|'.\$p->get_stock_quantity();")" "Old short|<p>Old long text</p><p>Second para</p>|19.99|7" "rollback: words restored, price + stock kept"

# Product SEO through Yoast.
R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaTitle\":\"T7 Mug SEO title\",\"metaDescription\":\"T7 mug description\"}}")
check "$(echo "$R" | jget "d['ok']")" "True" "product SEO through Yoast"
check "$(curl -sL "$($WP $S post url $P | tr -d '\r')" | grep -c '<title>T7 Mug SEO title</title>')" "1" "front end: Yoast title once"

# Product category: text + SEO.
R=$(api $S GET "/terms?taxonomy=product_cat&search=T7")
check "$(echo "$R" | jget "$CAT in [t['id'] for t in d['items']]")" "True" "GET /terms?taxonomy=product_cat"
R=$(api $S GET "/terms/$CAT?taxonomy=product_cat")
check "$(echo "$R" | jget "d['description']")" "Old category text" "GET /terms/{id}: description"
check "$(echo "$R" | jget "d['seo']['plugin']")" "yoast" "GET /terms/{id}: SEO plugin"
R=$(action $S term.update "{\"termId\":$CAT,\"taxonomy\":\"product_cat\",\"fields\":{\"description\":\"<p>New category text</p>\"}}")
check "$(echo "$R" | jget "d['result']['after']['description']")" "<p>New category text</p>" "term.update product_cat description"
R=$(action $S term.update "{\"termId\":$CAT,\"taxonomy\":\"product_cat\",\"fields\":{\"price\":\"1\"}}")
check "$(code "$R")" "invalid" "term.update refuses other fields"
R=$(action $S seo_meta.update "{\"termId\":$CAT,\"taxonomy\":\"product_cat\",\"fields\":{\"metaTitle\":\"T7 Mugs SEO\"}}")
check "$(echo "$R" | jget "d['result']['after']['metaTitle']")" "T7 Mugs SEO" "product category SEO through Yoast"

summary
