#!/bin/bash
# #310 plugin part: POST /media copies an image into the media library with
# alt text, title, caption, a readable file name, attached to the post;
# same image = one upload; GET /media?id= for re-use; non-images refused.
cd "$(dirname "$0")"; . ./lib.sh
S=1
for c in viswp-$S; do docker cp -q fixtures/og-1200x630.png $c:/var/www/html/vis-test-og.png; done
P=$($WP $S post create --post_status=draft --post_title="Media post $RANDOM" --porcelain | tail -1)
T=$RANDOM
SRC="http://localhost/vis-test-og.png?v=$T"
R=$(api $S POST /media "{\"source_url\":\"$SRC\",\"title\":\"SEO audit checklist\",\"alt_text\":\"Checklist of twelve SEO audit steps\",\"caption\":\"The 12 steps\",\"filename\":\"SEO Audit Checklist $T\",\"post_id\":$P}")
ID=$(echo "$R" | jget "['id']")
check "$(echo "$R" | jget "['mediaId'] == d['id'] and d['id'] > 0")" "True" "returns id and mediaId"
check "$(echo "$R" | jget "['filename']")" "seo-audit-checklist-$T.png" "readable file name, extension from the real type"
check "$(echo "$R" | jget "['altText']")" "Checklist of twelve SEO audit steps" "alt text"
check "$(echo "$R" | jget "['title']")" "SEO audit checklist" "title"
check "$(echo "$R" | jget "['caption']")" "The 12 steps" "caption"
check "$(echo "$R" | jget "['postId']")" "$P" "attached to the post"
check "$(echo "$R" | jget "['reused']")" "False" "first upload not reused"
check "$(echo "$R" | jget "list((d['width'], d['height']))")" "[1200, 630]" "dimensions"
check "$($WP $S post get $ID --field=post_parent)" "$P" "post_parent in the media library"
check "$(curl -s -o /dev/null -w '%{http_code}' "$(echo "$R" | jget "['url']")")" "200" "file served from this site"
# Same image again -> one upload; alt updated; old 'alt' key still accepted.
R2=$(api $S POST /media "{\"source_url\":\"$SRC\",\"alt\":\"Updated alt\",\"post_id\":$P}")
check "$(echo "$R2" | jget "['id']")" "$ID" "same source_url -> same attachment"
check "$(echo "$R2" | jget "['reused']")" "True" "reused:true"
check "$(echo "$R2" | jget "['altText']")" "Updated alt" "'alt' key accepted, alt updated"
check "$($WP $S post list --post_type=attachment --post_parent=$P --format=count)" "1" "still one attachment on the post"
# Lookup by id.
check "$(api $S GET "/media?id=$ID" | jget "['items'][0]['id']")" "$ID" "GET /media?id="
# Not an image.
CODE=$(curl -s -o /tmp/m.json -w '%{http_code}' -X POST -H "Authorization: Bearer vis-test-token-$S" -H 'Content-Type: application/json' --data '{"source_url":"http://localhost/wp-login.php"}' http://localhost:8091/wp-json/visibility/v1/media)
check "$CODE" "400" "non-image refused"
check "$(jget "['code']" < /tmp/m.json)" "visibility_not_an_image" "error code visibility_not_an_image"
# Executor action shares the code path.
A=$(action $S media.upload_from_url "{\"sourceUrl\":\"http://localhost/vis-test-og.png?a=$T\",\"altText\":\"Exec alt\",\"filename\":\"exec-image-$T\",\"postId\":$P}")
check "$(echo "$A" | jget "['result']['filename']")" "exec-image-$T.png" "media.upload_from_url: file name"
check "$(echo "$A" | jget "['result']['postId']")" "$P" "media.upload_from_url: attached"
summary
