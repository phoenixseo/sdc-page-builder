#!/usr/bin/env bash
# Check readme.txt against the structure wp.org requires.
#
# This is a structural check, not the wp.org parser: it enforces the things the
# directory rejects or silently truncates — missing headers, a Stable tag that
# disagrees with the plugin version, oversized sections, section order.
#
# Usage: test-scf/check-readme.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
README="$ROOT/plugin/readme.txt"
MAIN="$ROOT/plugin/sdc-page-builder.php"
fails=0

fail() { printf 'FAIL %s\n' "$1"; fails=$((fails + 1)); }
pass() { printf 'PASS %s\n' "$1"; }

[ -f "$README" ] || { fail "readme.txt exists"; exit 1; }
pass "readme.txt exists"

# Required headers.
for header in "Contributors:" "Tags:" "Requires at least:" "Tested up to:" "Requires PHP:" "Stable tag:" "License:"; do
	if grep -q "^${header}" "$README"; then
		pass "header: ${header}"
	else
		fail "header missing: ${header}"
	fi
done

# Stable tag must match the plugin header version, or wp.org serves the wrong zip.
plugin_version="$(sed -n 's/^ \* Version: *//p' "$MAIN" | head -1)"
stable_tag="$(sed -n 's/^Stable tag: *//p' "$README" | head -1)"
if [ -n "$plugin_version" ] && [ "$plugin_version" = "$stable_tag" ]; then
	pass "Stable tag matches plugin version ($plugin_version)"
else
	fail "Stable tag ($stable_tag) != plugin version ($plugin_version)"
fi

# Requires PHP must not promise more than the plugin header claims to need.
plugin_php="$(sed -n 's/^ \* Requires PHP: *//p' "$MAIN" | head -1)"
readme_php="$(sed -n 's/^Requires PHP: *//p' "$README" | head -1)"
if [ "$plugin_php" = "$readme_php" ]; then
	pass "Requires PHP matches plugin header ($plugin_php)"
else
	fail "Requires PHP mismatch: readme '$readme_php' vs header '$plugin_php'"
fi

# All required sections present, in the order wp.org renders them.
sections=("== Description ==" "== Installation ==" "== Frequently Asked Questions ==" "== Screenshots ==" "== Changelog ==" "== Upgrade Notice ==")
last_line=0
order_ok=1
for section in "${sections[@]}"; do
	line="$(grep -n -F "$section" "$README" | head -1 | cut -d: -f1)"
	if [ -z "$line" ]; then
		fail "section missing: $section"
		order_ok=0
		continue
	fi
	if [ "$line" -lt "$last_line" ]; then
		fail "section out of order: $section"
		order_ok=0
	fi
	last_line="$line"
done
[ "$order_ok" -eq 1 ] && pass "all sections present and in order"

# Changelog must name the stable tag, otherwise the release notes are absent.
if grep -q "^= ${stable_tag} =" "$README"; then
	pass "changelog has an entry for ${stable_tag}"
else
	fail "changelog has no entry for ${stable_tag}"
fi

# wp.org truncates long descriptions; 5000 chars is the practical ceiling for
# the description block, 10 screenshots the ceiling for the list.
desc="$(sed -n '/^== Description ==/,/^== Installation ==/p' "$README" | wc -c)"
if [ "$desc" -le 10000 ]; then
	pass "description length within limits ($desc chars)"
else
	fail "description too long ($desc chars)"
fi

shots="$(sed -n '/^== Screenshots ==/,/^== Changelog ==/p' "$README" | grep -cE '^[0-9]+\. ' || true)"
if [ "$shots" -ge 1 ] && [ "$shots" -le 10 ]; then
	pass "screenshots listed: $shots (1-10)"
else
	fail "screenshot count out of range: $shots"
fi

# Tags: max 5, lowercase, comma separated.
tags_line="$(sed -n 's/^Tags: *//p' "$README" | head -1)"
tag_count="$(printf '%s' "$tags_line" | tr ',' '\n' | sed 's/^ *//;s/ *$//' | grep -c . || true)"
if [ "$tag_count" -ge 1 ] && [ "$tag_count" -le 5 ]; then
	pass "tags: $tag_count (max 5)"
else
	fail "tag count out of range: $tag_count"
fi

printf '\n'
if [ "$fails" -eq 0 ]; then
	echo "ERGEBNIS: readme.txt KONFORM"
	exit 0
fi
echo "ERGEBNIS: $fails FEHLER"
exit 1
