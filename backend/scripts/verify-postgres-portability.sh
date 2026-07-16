#!/bin/sh
# Guards the two MySQL-isms that the Postgres switch (issue #19) had to fix, and that
# `php artisan test` structurally cannot catch: the suite runs on SQLite, which — like
# MySQL and unlike Postgres — has a case-insensitive LIKE and tolerates a select alias
# inside an ORDER BY expression. A green suite over broken search is exactly what this
# exists to prevent, so it drives the running stack instead.
#
#   [API=http://localhost:8010/api/v1] sh backend/scripts/verify-postgres-portability.sh
#
# Run it against a stack on the real engine (`docker compose up`). Exits non-zero on
# any failure.
set -u

API="${API:-http://localhost:8010/api/v1}"
failures=0

pass() { echo "PASS  $1"; }
fail() {
    echo "FAIL  $1 — $2"
    failures=$((failures + 1))
}

# meta.total, not a count of matched keys in data — every fragrance carries a nested
# brand, so key-counting silently doubles and reports a number that isn't the answer.
count() { curl -s "$API/fragrances?q=$1" | grep -o '"total":[0-9]*' | head -1 | cut -d: -f2; }
status() { curl -s -o /dev/null -w '%{http_code}' "$API/fragrances?sort=$1"; }

# 1. Case-insensitive search. Postgres's LIKE is case-sensitive, so a bare
#    where(..., 'like', ...) silently returns nothing for a lowercase query.
#    Compares against a mixed-case control rather than a hardcoded count, so this
#    keeps working as the catalog changes.
control=$(count "Creed")
if [ "$control" -eq 0 ]; then
    echo "SKIP  no fragrance matches the 'Creed' probe — search casing not exercised"
else
    for variant in creed CREED cReEd; do
        got=$(count "$variant")
        if [ "$got" -eq "$control" ]; then
            pass "search q=$variant matches the same $control result(s) as q=Creed"
        else
            fail "search q=$variant" "got $got, expected $control — LIKE went case-sensitive"
        fi
    done
fi

# 2. Every sort works. price_asc/price_desc order by min_price, a withMin() select
#    alias; Postgres accepts an alias in ORDER BY only as a bare name, so putting it
#    in an expression raises "column min_price does not exist" and 500s.
for sort in price_asc price_desc name newest; do
    code=$(status "$sort")
    if [ "$code" = "200" ]; then
        pass "sort=$sort returns 200"
    else
        fail "sort=$sort" "HTTP $code"
    fi
done

if [ "$failures" -eq 0 ]; then
    echo "\nall checks passed"
    exit 0
fi
echo "\n$failures check(s) failed"
exit 1
