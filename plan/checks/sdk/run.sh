#!/usr/bin/env bash
# Area 4 of the plan review: proposal digest vectors and text.replace_exact@1 reference.
# One command from the repository root on a clean checkout:
#   bash plan/checks/sdk/run.sh
# Needs PHP 8.4 (mbstring), Node >= 20 (measured on 24.12.0), Python 3 + jsonschema 4.x,
# composer and npm with registry access (lockfiles pin every dependency).
set -euo pipefail
cd "$(dirname "$0")/../../.."
REF=plan/examples/reference
echo "== versions"; php -r 'echo "PHP ", PHP_VERSION, "\n";'; node --version; python3 --version

echo "== proposal digest: independent Python reference, naive variants, ContentHasher, field coverage"
python3 plan/checks/sdk/digest_vectors.py | grep -v '^ok   digest changes with' || exit 1
echo "== proposal digest: PHP 8.4 stdlib"
php $REF/proposal-digest/php/ProposalDigest.php
echo "== proposal digest: Node stdlib"
node $REF/proposal-digest/node/proposal-digest.mjs
echo "== mmccook/php-json-canonicalization-scheme 1.0.0 (pinned in its own composer.lock)"
composer install --quiet --no-interaction --no-progress --working-dir $REF/proposal-digest/php-mmccook
php $REF/proposal-digest/php-mmccook/compare.php

echo "== text.replace_exact@1: ProseMirror reference vs PHP mirror, sensitivity, verify at_commit/current"
npm ci --silent --no-audit --no-fund --prefix $REF/text-replace
node --test plan/checks/sdk/text-replace.test.mjs
echo "== wire exchange as the adapter checks it"
python3 plan/checks/sdk/text_exchange.py
echo "OK area 4"
