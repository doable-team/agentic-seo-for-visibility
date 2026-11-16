#!/bin/bash
# C-38 Bricks, C-39 Oxygen, C-40 Breakdance (paid; STAND-IN that only defines
# each builder's version constant, page data written in each builder's
# documented storage format): detected in /capabilities (writable false,
# readable true), a page reports builder:<slug>, its elements (text, heading
# level, link, image alt from the library) are listed in page order, and
# every edit is refused plainly with nothing written.
cd "$(dirname "$0")"; . ./lib.sh
S=${SITE:-2}
$CTR cp standins/readonly-builders-standin.php ${CPFX}$S:/var/www/html/wp-content/mu-plugins/readonly-builders-standin.php
api $S POST /events/site-config-changed '{"controls":null,"allowPublish":true,"disabled":false}' >/dev/null
IMG=$(api $S POST /media '{"source_url":"http://localhost/vis-test-og.png","title":"RO img","alt_text":"Library alt"}' | jget "['id']")
page() { $WP $S post create --post_type=page --post_status=publish --post_title="$1 $RANDOM" --post_content='<p>fallback</p>' --porcelain | tr -d '\r' | tail -1; }
run() { # run <slug> <reported slug> <page id> <expected element list>
  local b=$1 slug=$2 P=$3 want=$4
  $WP $S option update vis_standin_builder $b >/dev/null
  C=$(api $S GET /capabilities)
  check "$(echo "$C" | jget "list((x['slug'],x['writable'],x['readable']) for x in d['builders'] if x['slug']=='$slug')")" "[('$slug', False, True)]" "$b: capabilities lists $slug read-only"
  check "$(echo "$C" | jget "'builder.read' in d['features']")" "True" "$b: feature builder.read"
  R=$(api $S GET "/posts/$P?include=builder")
  check "$(echo "$R" | jget "(d['editor'], d['builder']['writable'], d['builder']['readable'])")" "('builder:$slug', False, True)" "$b: page is builder:$slug, read-only"
  check "$(echo "$R" | jget "list((e['widgetType'], e['text'], e['attrs'].get('level'), e['attrs'].get('href'), e['attrs'].get('alt'), e['editable']) for e in d['elements'])")" "$want" "$b: elements in order with text, level, link, alt"
  BEFORE=$($WP $S post meta list $P --format=json | md5sum)
  R=$(action $S page.edit_parts "{\"postId\":$P,\"edits\":[{\"target\":{\"elementId\":\"x\"},\"op\":\"replace_text\",\"text\":\"New\"}]}")
  check "$(echo "$R" | jget "(d['errorCode'], 'can only read' in d['error'])")" "('unsupported', True)" "$b: edit refused plainly (can only read)"
  check "$($WP $S post meta list $P --format=json | md5sum)" "$BEFORE" "$b: nothing written"
  R=$(action $S seo_meta.update "{\"postId\":$P,\"fields\":{\"metaDescription\":\"SEO still works on $b pages\"}}")
  check "$(echo "$R" | jget "d['ok']")" "True" "$b: SEO of the page can still be changed"
}
# Bricks: flat element array.
P=$(page "Bricks")
$WP $S eval "update_post_meta($P, '_bricks_editor_mode', 'bricks'); update_post_meta($P, '_bricks_page_content_2', [
 ['id'=>'sec001','name'=>'section','parent'=>0,'children'=>['hd0001','tx0001','im0001','bt0001'],'settings'=>[]],
 ['id'=>'hd0001','name'=>'heading','parent'=>'sec001','children'=>[],'settings'=>['text'=>'Bricks heading','tag'=>'h1']],
 ['id'=>'tx0001','name'=>'text-basic','parent'=>'sec001','children'=>[],'settings'=>['text'=>'<p>Bricks text</p>']],
 ['id'=>'im0001','name'=>'image','parent'=>'sec001','children'=>[],'settings'=>['image'=>['id'=>$IMG,'url'=>'x','size'=>'full']]],
 ['id'=>'bt0001','name'=>'button','parent'=>'sec001','children'=>[],'settings'=>['text'=>'Buy','link'=>['type'=>'external','url'=>'https://example.com/buy']]]]);" >/dev/null
run bricks bricks $P "[('heading', 'Bricks heading', 1, None, None, False), ('text-basic', 'Bricks text', None, None, None, False), ('image', '', None, None, 'Library alt', False), ('button', 'Buy', None, 'https://example.com/buy', None, False)]"
# Oxygen classic: ct_builder_json.
P=$(page "Oxygen")
$WP $S eval "update_post_meta($P, 'ct_builder_json', wp_slash(wp_json_encode(['children'=>[['id'=>1,'name'=>'ct_section','options'=>['ct_id'=>1],'children'=>[
 ['id'=>2,'name'=>'ct_headline','options'=>['ct_content'=>'Oxygen headline','original'=>['tag'=>'h2']]],
 ['id'=>3,'name'=>'ct_text_block','options'=>['ct_content'=>'Oxygen <b>text</b>']],
 ['id'=>4,'name'=>'ct_image','options'=>['original'=>['attachment_id'=>$IMG,'src'=>'x']]],
 ['id'=>5,'name'=>'ct_link_button','options'=>['ct_content'=>'Read more','original'=>['url'=>'/more/']]]]]]])));" >/dev/null
run oxygen oxygen $P "[('ct_headline', 'Oxygen headline', 2, None, None, False), ('ct_text_block', 'Oxygen text', None, None, None, False), ('ct_image', '', None, None, 'Library alt', False), ('ct_link_button', 'Read more', None, '/more/', None, False)]"
# Breakdance: _breakdance_data.tree_json_string.
BDTREE="['root'=>['id'=>1,'data'=>['type'=>'root','properties'=>[]],'children'=>[['id'=>100,'data'=>['type'=>'EssentialElements\\\\Section','properties'=>[]],'children'=>[
 ['id'=>101,'data'=>['type'=>'EssentialElements\\\\Heading','properties'=>['content'=>['content'=>['text'=>'BD heading','tags'=>'h3']]]],'children'=>[]],
 ['id'=>102,'data'=>['type'=>'EssentialElements\\\\RichText','properties'=>['content'=>['content'=>['text'=>'<p>BD text</p>']]]],'children'=>[]],
 ['id'=>103,'data'=>['type'=>'EssentialElements\\\\Image','properties'=>['content'=>['content'=>['image'=>['id'=>$IMG,'url'=>'x']]]]],'children'=>[]],
 ['id'=>104,'data'=>['type'=>'EssentialElements\\\\Button','properties'=>['content'=>['content'=>['text'=>'Book','link'=>['url'=>'https://example.com/book']]]]],'children'=>[]]]]]],'_nextNodeId'=>105,'status'=>'exported']"
P=$(page "Breakdance")
$WP $S eval "update_post_meta($P, '_breakdance_data', wp_slash(wp_json_encode(['tree_json_string'=>wp_json_encode($BDTREE)])));" >/dev/null
WANT_BD="[('Heading', 'BD heading', 3, None, None, False), ('RichText', 'BD text', None, None, None, False), ('Image', '', None, None, 'Library alt', False), ('Button', 'Book', None, 'https://example.com/book', None, False)]"
run breakdance breakdance $P "$WANT_BD"
# Oxygen 6 (Breakdance engine, _oxygen_ prefix).
P=$(page "Oxygen6")
$WP $S eval "update_post_meta($P, '_oxygen_data', wp_slash(wp_json_encode(['tree_json_string'=>wp_json_encode($BDTREE)])));" >/dev/null
run oxygen6 oxygen $P "$WANT_BD"
$WP $S option delete vis_standin_builder >/dev/null
$CTR exec ${CPFX}$S rm -f /var/www/html/wp-content/mu-plugins/readonly-builders-standin.php
summary
