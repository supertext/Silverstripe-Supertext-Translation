#!/usr/bin/env bash
# CI: starts the demo image twice against MySQL with the stand-in API, checks the demo accounts
# (created once, never duplicated, passwords never logged) and translates the sample article as
# the editor, then the Commando Center parts: the public ping route and the version reports, sent to
# the stand-in (never the live center). Needs: docker image supertext-silverstripe-demo, MySQL at
# $MYSQL_URL (root), the `mysql` client, `jq`, and the stand-in on 127.0.0.1:8765.
set -euo pipefail
PORT=8080
B=http://127.0.0.1:$PORT
DB=silverstripe
export DEMO_ADMIN_EMAIL=ci-admin@example.com DEMO_ADMIN_PASSWORD="Ci-$(openssl rand -hex 12)"
export DEMO_EDITOR_EMAIL=ci-editor@example.com DEMO_EDITOR_PASSWORD="Ci-$(openssl rand -hex 12)"
q() { mysql ${MYSQL_ARGS:--h 127.0.0.1 -P 3306 -uroot -proot} -N --default-character-set=utf8mb4 "$DB" -e "$1"; }
sake() { docker exec demo vendor/bin/sake "$@"; }

start() {
	docker rm -f demo >/dev/null 2>&1 || true
	docker run -d --name demo --network host -e PORT=$PORT -e DATABASE_URL="$MYSQL_URL" -e SS_BASE_URL=$B \
		-e DEMO_ADMIN_EMAIL -e DEMO_ADMIN_PASSWORD -e DEMO_EDITOR_EMAIL -e DEMO_EDITOR_PASSWORD \
		-e SUPERTEXT_API_KEY=anything -e SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ \
		-e SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report supertext-silverstripe-demo >/dev/null
	for _ in $(seq 90); do curl -sf -o /dev/null $B/Security/login && break; sleep 2; done
	curl -sf -o /dev/null $B/Security/login
	docker logs demo 2>&1 | grep '\[demo\]' || true
}

start
start   # second start: nothing duplicated or changed
docker logs demo 2>&1 | grep > /dev/null 'DEMO_EDITOR: account exists, left unchanged'
logs=$(docker logs demo 2>&1)
if grep -qF -e "$DEMO_ADMIN_PASSWORD" -e "$DEMO_EDITOR_PASSWORD" <<< "$logs"; then echo "A password appeared in the log"; exit 1; fi

test "$(q "select count(*) from Member")" = 2
test "$(q "select g.Code from Member m join Group_Members gm on gm.MemberID=m.ID join \`Group\` g on g.ID=gm.GroupID where m.Email='ci-admin@example.com'")" = administrators
test "$(q "select g.Code from Member m join Group_Members gm on gm.MemberID=m.ID join \`Group\` g on g.ID=gm.GroupID where m.Email='ci-editor@example.com'")" = supertext-editors
test "$(q "select count(*) from Permission p join \`Group\` g on g.ID=p.GroupID where g.Code='supertext-editors' and p.Code in ('SUPERTEXT_TRANSLATE','CMS_ACCESS_CMSMain')")" = 2
test "$(q "select group_concat(Locale order by Sort) from Fluent_Locale")" = "en_US,de_CH,fr_CH,it_CH"
echo "accounts and locales OK"

sake tasks:supertext-check | grep > /dev/null 'API key checked: the connection to Supertext works'
PAGE=$(q "select ID from SiteTree where URLSegment='swiss-chocolate-shipped-worldwide'")
sake tasks:supertext-translate --page="$PAGE" --from=en_US --member=ci-editor@example.com | tee /tmp/translate.log
test "$(grep -c ': translated' /tmp/translate.log)" = 3
sake tasks:supertext-translate --page="$PAGE" --from=en_US --to=de_CH --member=ci-editor@example.com | grep > /dev/null 'skipped'

test "$(q "select Title from SiteTree_Localised where RecordID=$PAGE and Locale='de_CH'")" = "Schweizer Schokolade, weltweit versandt"
test "$(q "select URLSegment from SiteTree_Localised where RecordID=$PAGE and Locale='fr_CH'")" = "chocolat-suisse-expedie-dans-le-monde-entier"
q "select HTML from ElementContent_Localised where Locale='de_CH'" | grep > /dev/null '<strong>Berner</strong>'
q "select HTML from ElementContent_Localised where Locale='de_CH'" | grep > /dev/null 'href="https://www.supertext.com"'
test "$(q "select count(*) from Element_Localised where Locale='it_CH'")" = 3
test "$(q "select Title from Element_Localised where Locale='fr_CH' order by RecordID limit 1")" = "De Berne vers le monde"
test "$(q "select count(*) from SupertextTranslation where Status='translated'")" = 3

# Export for translation → import translation through the Supertext tab's endpoints, as the editor.
jar=$(mktemp)
login=$(curl -sf -c "$jar" -b "$jar" "$B/Security/login")
action=$(grep -o '<form[^>]*id="MemberLoginForm_LoginForm"[^>]*' <<< "$login" | grep -o 'action="[^"]*"' | sed 's/^action="//; s/"$//; s/&amp;/\&/g')
token=$(grep -o 'name="SecurityID" value="[^"]*"' <<< "$login" | head -1 | sed 's/.*value="//; s/"$//')
curl -sf -c "$jar" -b "$jar" -o /dev/null --data-urlencode "Email=$DEMO_EDITOR_EMAIL" --data-urlencode "Password=$DEMO_EDITOR_PASSWORD" \
	--data-urlencode "SecurityID=$token" --data-urlencode 'AuthenticationMethod=SilverStripe\Security\MemberAuthenticator\MemberAuthenticator' \
	--data-urlencode 'action_doLogin=Log in' "$B$action"
edit=$(curl -sf -c "$jar" -b "$jar" "$B/admin/pages/edit/show/$PAGE")
grep > /dev/null 'supertext-export' <<< "$edit"
token=$(grep -o 'name="SecurityID" value="[^"]*"' <<< "$edit" | head -1 | sed 's/.*value="//; s/"$//')
curl -sf -b "$jar" -D /tmp/export.headers -o /tmp/export.html -F "SecurityID=$token" -F source=en_US -F format=html -F 'targets[]=de_CH' \
	"$B/admin/pages/edit/supertextExport/$PAGE"
grep -qi "^content-disposition: attachment; filename=\"silverstripe-$PAGE-de_CH.html\"" /tmp/export.headers
grep > /dev/null 'name="supertext:plugin" content="Silverstripe-Supertext-Translation"' /tmp/export.html
sed 's/>Swiss chocolate, shipped worldwide</>Schweizer Schokolade aus der Datei</g' /tmp/export.html > /tmp/import.html
imported=$(curl -sf -b "$jar" -F "SecurityID=$token" -F "file=@/tmp/import.html;type=text/html" -F overwrite=1 "$B/admin/pages/edit/supertextImport")
jq -e '.html | contains("supertext-import-summary")' <<< "$imported" > /dev/null
test "$(q "select Title from SiteTree_Localised where RecordID=$PAGE and Locale='de_CH'")" = "Schweizer Schokolade aus der Datei"
# Without "overwrite" an existing translation is skipped; a file of another system is refused.
curl -sf -b "$jar" -F "SecurityID=$token" -F "file=@/tmp/import.html;type=text/html" "$B/admin/pages/edit/supertextImport" | jq -e '.html | contains("skipped") or contains("übersprungen")' > /dev/null
sed 's/Silverstripe-Supertext-Translation/Contao-Supertext-Translation/' /tmp/import.html > /tmp/other.html
curl -sf -b "$jar" -F "SecurityID=$token" -F "file=@/tmp/other.html;type=text/html" "$B/admin/pages/edit/supertextImport" | jq -e '.html | contains("Contao-Supertext-Translation")' > /dev/null
echo "translation files OK"

# Commando Center: public ping (no login), version reports to the stand-in, state in SupertextState.
test "$(curl -s "$B/supertext/ping?challenge=ci-challenge-0123456789")" = '{"ok":true,"plugin":"Silverstripe-Supertext-Translation","challenge":"ci-challenge-0123456789"}'
test "$(curl -s -o /dev/null -w '%{http_code}' "$B/supertext/ping?challenge=bad")" = 400
headers=$(curl -s -D - -o /dev/null "$B/supertext/ping?challenge=ci-challenge-0123456789")
grep -qi '^cache-control:.*no-store' <<< "$headers"
reports=$(curl -sf http://127.0.0.1:8765/reports)
jq -e --arg cb "$B/supertext/ping" 'map(select(.event == "installed" and .plugin.name == "Silverstripe-Supertext-Translation" and .system.name == "Silverstripe CMS" and .callback.url == $cb and .api_key_check.status == "ok")) | length == 1' <<< "$reports" > /dev/null
jq -e 'map(select(.event == "check")) | length >= 1' <<< "$reports" > /dev/null
if grep -qF '"anything"' <<< "$reports"; then echo "The API key appeared in a report"; exit 1; fi
q "select Value from SupertextState where \`Key\`='reporter'" | grep > /dev/null '"status":"ok"'
echo "Commando Center check passed"
echo "Demo check passed"
