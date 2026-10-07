#!/usr/bin/env bash
# Sends a correctly signed test webhook to the service.
#
# Usage:
#   WEBHOOK_SECRET=... scripts/send-webhook.sh <type> <payment_id> <amount> [currency] [event_id]
#
# Examples:
#   WEBHOOK_SECRET=s3cret scripts/send-webhook.sh payment.succeeded pay_1 1500 USD
#   WEBHOOK_SECRET=s3cret scripts/send-webhook.sh payment.refunded  pay_1 500
#
# Environment:
#   WEBHOOK_SECRET  required, must match WEBHOOK_DEMO_SECRETS of the service
#   WEBHOOK_URL     default http://localhost:8000/api/webhooks/demo
set -euo pipefail

URL="${WEBHOOK_URL:-http://localhost:8000/api/webhooks/demo}"
SECRET="${WEBHOOK_SECRET:?Set WEBHOOK_SECRET to the secret configured on the service}"
TYPE="${1:?event type, for example payment.succeeded}"
PAYMENT_ID="${2:?payment id, for example pay_1}"
AMOUNT="${3:?amount in minor units, for example 1500}"
CURRENCY="${4:-USD}"
EVENT_ID="${5:-evt_$(openssl rand -hex 6)}"

TIMESTAMP="$(date +%s)"
BODY="$(printf '{"id":"%s","type":"%s","created":%s,"data":{"payment_id":"%s","amount":%s,"currency":"%s"}}' \
    "$EVENT_ID" "$TYPE" "$TIMESTAMP" "$PAYMENT_ID" "$AMOUNT" "$CURRENCY")"

# The signed string is "<timestamp>.<raw body>", exactly what the service verifies.
SIGNATURE="$(printf '%s.%s' "$TIMESTAMP" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"

echo "event: $EVENT_ID"
curl -sS -X POST "$URL" \
    -H "Content-Type: application/json" \
    -H "X-Webhook-Signature: t=${TIMESTAMP},v1=${SIGNATURE}" \
    --data-binary "$BODY" \
    -w '\nHTTP %{http_code}\n'
