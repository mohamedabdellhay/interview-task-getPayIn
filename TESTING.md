# Testing Documentation

## Overview

This document describes the automated test suite for the Flash-Sale Checkout API, covering concurrency control, hold expiry, and webhook idempotency.

---

## Running Tests

### All Tests

```bash
./vendor/bin/sail artisan test
```

### Specific Test Class

```bash
./vendor/bin/sail artisan test --filter=HoldConcurrencyTest
```

### With Coverage

```bash
./vendor/bin/sail artisan test --coverage
```

### Parallel Execution

```bash
./vendor/bin/sail artisan test --parallel --processes=4
```

---

## Test Suites

### 1. HoldConcurrencyTest

**Purpose:** Verify that parallel hold attempts at stock boundaries do not cause overselling.

#### Tests:

-   `test_parallel_holds_prevent_overselling()`

    -   **Scenario:** 10 users simultaneously try to reserve the last item
    -   **Expected:** Only 1 succeeds, 9 receive "insufficient stock" error
    -   **Validates:** Pessimistic locking prevents race conditions

-   `test_multiple_holds_within_stock_succeed()`

    -   **Scenario:** Multiple users reserve items within available stock
    -   **Expected:** All requests succeed until stock is depleted
    -   **Validates:** Normal operations work correctly

-   `test_hold_exceeding_stock_fails()`
    -   **Scenario:** User tries to reserve more than available stock
    -   **Expected:** Request fails with 409 Conflict
    -   **Validates:** Validation prevents overselling

**Key Assertions:**

-   Only one hold succeeds when stock = 1
-   Database has exactly one active hold
-   Available stock correctly reflects reservations

---

### 2. HoldExpiryTest

**Purpose:** Verify that expired holds automatically release stock and restore availability.

#### Tests:

-   `test_expired_holds_return_availability()`

    -   **Scenario:** Hold expires after 2 minutes
    -   **Expected:** Stock becomes available again, hold status = expired
    -   **Validates:** Automatic expiry releases reservations

-   `test_active_holds_not_expired()`

    -   **Scenario:** Expiry command runs while active holds exist
    -   **Expected:** Active holds remain unchanged
    -   **Validates:** Expiry only affects expired holds

-   `test_multiple_expired_holds_processed()`

    -   **Scenario:** Multiple holds expire simultaneously
    -   **Expected:** All expired holds processed, stock fully restored
    -   **Validates:** Batch expiry works correctly

-   `test_consumed_holds_not_expired()`
    -   **Scenario:** Consumed hold (used in order) has passed expiry time
    -   **Expected:** Hold remains consumed, not marked as expired
    -   **Validates:** Consumed holds are not affected by expiry

**Key Assertions:**

-   Expired holds change status to "expired"
-   Available stock increases after expiry
-   Active and consumed holds are not affected

---

### 3. WebhookIdempotencyTest

**Purpose:** Verify that duplicate webhooks are handled safely without double-processing.

#### Tests:

-   `test_duplicate_webhooks_are_idempotent()`

    -   **Scenario:** Same webhook sent twice with identical idempotency_key
    -   **Expected:** First processes normally, second returns "already processed"
    -   **Validates:** Idempotency prevents duplicate stock deduction

-   `test_different_webhooks_process_independently()`

    -   **Scenario:** Two different webhooks with different keys
    -   **Expected:** Both process independently
    -   **Validates:** Idempotency doesn't block legitimate webhooks

-   `test_failed_payment_cancels_order()`
    -   **Scenario:** Webhook indicates payment failure
    -   **Expected:** Order cancelled, stock not deducted
    -   **Validates:** Failed payments are handled correctly

**Key Assertions:**

-   Stock deducted only once despite duplicate webhooks
-   Only one PaymentWebhook record per idempotency_key
-   Order status correctly reflects payment result

---

### 4. WebhookOrderRaceTest

**Purpose:** Verify handling of webhooks that arrive before order creation completes.

#### Tests:

-   `test_webhook_before_order_creation_returns_202()`

    -   **Scenario:** Webhook arrives for non-existent order
    -   **Expected:** Returns 202 Accepted after retries
    -   **Validates:** Out-of-order webhooks handled gracefully

-   `test_webhook_processes_when_order_exists()`

    -   **Scenario:** Normal webhook arrival after order creation
    -   **Expected:** Processes successfully
    -   **Validates:** Normal flow works correctly

-   `test_concurrent_order_creation_and_webhook()`

    -   **Scenario:** Order creation and webhook happen simultaneously
    -   **Expected:** Both complete successfully, final state is correct
    -   **Validates:** Race conditions don't cause data corruption

-   `test_multiple_webhooks_same_order_different_keys()`
    -   **Scenario:** Multiple webhooks for same order with different keys
    -   **Expected:** All accepted, but stock only deducted once
    -   **Validates:** Order state transitions are safe

**Key Assertions:**

-   Webhook retry logic handles missing orders
-   Final order status is consistent
-   Stock deducted exactly once regardless of timing

---

## Test Coverage

### Models

-   Product: Stock calculation, caching, safe decrement
-   Hold: Creation, expiry, consumption
-   Order: Creation from hold, state transitions
-   PaymentWebhook: Idempotent processing

### Controllers

-   ProductController: Stock retrieval with caching
-   HoldController: Concurrent hold creation, deadlock handling
-   OrderController: Order creation validation
-   PaymentWebhookController: Idempotency, out-of-order handling

### Commands

-   ExpireHoldsCommand: Batch expiry processing

---

## Manual Testing Scenarios

### Scenario 1: Flash Sale Simulation

```bash
# Create product with limited stock
./vendor/bin/sail artisan db:seed --class=ProductSeeder

# Simulate 100 concurrent hold requests
for i in {1..100}; do
  curl -X POST http://localhost:8000/api/holds \
    -H "Content-Type: application/json" \
    -d '{"product_id": 1, "qty": 1}' &
done
wait

# Verify no overselling
curl http://localhost:8000/api/products/1
```

### Scenario 2: Hold Expiry

```bash
# Create a hold
HOLD_ID=$(curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 5}' | jq -r '.data.hold_id')

# Check available stock (should decrease)
curl http://localhost:8000/api/products/1

# Wait 2+ minutes or manually expire
./vendor/bin/sail artisan holds:expire

# Check available stock again (should increase)
curl http://localhost:8000/api/products/1
```

### Scenario 3: Duplicate Webhook

```bash
# Create order
ORDER_ID=$(curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 1}' | jq -r '.data.order_id')

# Send webhook twice with same key
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d "{\"idempotency_key\": \"test-123\", \"order_id\": $ORDER_ID, \"status\": \"success\"}"

curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d "{\"idempotency_key\": \"test-123\", \"order_id\": $ORDER_ID, \"status\": \"success\"}"

# Check order status (should be paid)
# Check stock (should only decrease once)
```

---

## Performance Testing

### Load Test with Apache Bench

```bash
# Install
sudo apt-get install apache2-utils

# Test product endpoint
ab -n 1000 -c 100 http://localhost:8000/api/products/1

# Test hold creation
ab -n 500 -c 50 -p hold.json -T application/json \
  http://localhost:8000/api/holds
```

### Stress Test with wrk

```bash
# Install
sudo apt-get install wrk

# Run stress test
wrk -t4 -c100 -d30s http://localhost:8000/api/products/1
```

---

## Expected Results

### Test Suite

```
PASS  Tests\Feature\HoldConcurrencyTest
✓ parallel holds prevent overselling
✓ multiple holds within stock succeed
✓ hold exceeding stock fails

PASS  Tests\Feature\HoldExpiryTest
✓ expired holds return availability
✓ active holds not expired
✓ multiple expired holds processed
✓ consumed holds not expired

PASS  Tests\Feature\WebhookIdempotencyTest
✓ duplicate webhooks are idempotent
✓ different webhooks process independently
✓ failed payment cancels order

PASS  Tests\Feature\WebhookOrderRaceTest
✓ webhook before order creation returns 202
✓ webhook processes when order exists
✓ concurrent order creation and webhook
✓ multiple webhooks same order different keys

Tests:    14 passed (14 assertions)
Duration: < 1s
```

---

## Debugging Failed Tests

### View Logs

```bash
tail -f storage/logs/laravel.log
```

### Database Inspection

```bash
./vendor/bin/sail mysql

USE laravel;
SELECT * FROM holds WHERE status = 'active';
SELECT * FROM orders;
SELECT * FROM payment_webhooks;
```

### Enable Query Logging

In `.env`:

```
DB_LOG_QUERIES=true
```

---

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
    tests:
        runs-on: ubuntu-latest
        steps:
            - uses: actions/checkout@v2
            - name: Setup PHP
              uses: shivammathur/setup-php@v2
              with:
                  php-version: "8.4"
            - name: Install Dependencies
              run: composer install
            - name: Run Tests
              run: php artisan test
```

---

## Additional Test Ideas (Future)

-   [ ] Load test with 1000+ concurrent users
-   [ ] Chaos engineering: random database failures
-   [ ] Network delay simulation for webhooks
-   [ ] Memory leak detection under sustained load
-   [ ] Database deadlock frequency measurement
-   [ ] Cache hit rate optimization
