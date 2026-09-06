#!/usr/bin/env bash
# aibdeploy.sh — signerande klient för aib-deployer (mu-plugins/aib-deployer.php)
#
# Användning:
#   AIB_DEPLOYER_SECRET=<hex> ./aibdeploy.sh <site> '<json>'
#   (eller lägg hemligheten i ~/.aib-deployer-secret, en rad, hex)
#
# Exempel:
#   ./aibdeploy.sh fastsan.se '{"op":"stat","dest":"mu-plugins/fastsan-ga4.php"}'
#   ./aibdeploy.sh fastsan.se '{"op":"write","dest":"mu-plugins/x.php","url":"https://raw.githubusercontent.com/johanodsater/<repo>/<40-hex-sha>/mu-plugins/x.php","sha256":"<hex64>","purge":true}'
#   ./aibdeploy.sh fastsan.se '{"op":"rename","dest":"mu-plugins/x.php","to":"mu-plugins/x.php.off"}'
#   ./aibdeploy.sh fastsan.se '{"op":"delete","dest":"mu-plugins/x.php"}'
#
# Regler (verifieras server-side): url måste vara commit-pinnad; sha256 måste stämma; dest under
# mu-plugins/, themes/ eller uploads/fastsan-content/. Signatur: hex HMAC-SHA256(secret, "ts\nnonce\nbody").
set -euo pipefail
SITE="${1:?site saknas}"; BODY="${2:?json saknas}"
SECRET="${AIB_DEPLOYER_SECRET:-$(cat "$HOME/.aib-deployer-secret" 2>/dev/null || true)}"
[ -n "$SECRET" ] || { echo "AIB_DEPLOYER_SECRET saknas" >&2; exit 2; }
TS="$(date +%s)"; NONCE="$(openssl rand -hex 12)"
SIG="$(printf '%s\n%s\n%s' "$TS" "$NONCE" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"
curl -s -A "aib-deployer-client/1.0" -X POST "https://$SITE/?aib_deploy=1" \
  -H "Content-Type: application/json" -H "X-AIB-Ts: $TS" -H "X-AIB-Nonce: $NONCE" -H "X-AIB-Sig: $SIG" \
  --data-binary "$BODY" -w "\nHTTP %{http_code}\n"
