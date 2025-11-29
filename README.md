# Flash-Sale Checkout API

> A high-concurrency Laravel 12 API for flash-sale checkout operations with robust overselling prevention, idempotent payment webhooks, and automatic hold expiry management.

![PHP](https://img.shields.io/badge/PHP-8.3-blue?style=for-the-badge&logo=php)
![Laravel](https://img.shields.io/badge/Laravel-12-red?style=for-the-badge&logo=laravel)
![MySQL](https://img.shields.io/badge/MySQL-8.0-orange?style=for-the-badge&logo=mysql)
![Docker](https://img.shields.io/badge/Docker-29.0.2-blue?style=for-the-badge&logo=docker)
![Tests](https://img.shields.io/badge/Tests-Passing-brightgreen?style=for-the-badge)

---

## Table of Contents

-   [Features](#features)
-   [Architecture](#architecture)
-   [Quick Start](#quick-start)
-   [API Documentation](#api-documentation)
-   [Testing](#testing)
-   [How It Works](#how-it-works)
-   [Performance](#performance)
-   [Logs & Monitoring](#logs--monitoring)
-   [Troubleshooting](#troubleshooting)
-   [Task Requirements Compliance](#task-requirements-compliance)

---

## Features

### Core Functionality

-   **Product Endpoint**: Real-time stock availability with caching
-   **Hold System**: 2-minute temporary reservations
-   **Order Creation**: Convert holds to orders
-   **Payment Webhooks**: Idempotent webhook processing

### Concurrency & Safety

-   **No Overselling**: Pessimistic locking prevents race conditions
-   **Deadlock Recovery**: Automatic retry with exponential backoff (3 attempts)
-   **Idempotent Webhooks**: Safe duplicate webhook processing
-   **Out-of-Order Handling**: Webhooks arriving before order creation (5 retries)

### Performance

-   **Strategic Caching**: 10s product cache, 5s stock cache
-   **Fast Under Load**: Handles burst traffic efficiently
-   **Database Optimization**: Proper indexes and query optimization

### Automation

-   **Auto-Expiry**: Background job releases expired holds every minute
-   **Comprehensive Logging**: Detailed metrics for monitoring
-   **Robust Testing**: 14 automated tests covering all scenarios

---

## Architecture

### System Design

```
┌─────────────────────────────────────────────────────────────┐
│                    Flash Sale System                         │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  User Request → API Controller → Model (with Locking)        │
│                     ↓                                         │
│              Database Transaction                             │
│                     ↓                                         │
│         ┌───────────┴───────────┐                            │
│         ↓                       ↓                             │
│    Success Response      Cache Invalidation                  │
│                                                               │
│  Background: Scheduler → ExpireHoldsCommand (every minute)   │
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

### Database Schema

```sql
products
├── id              (primary key)
├── name            (string)
├── price           (decimal)
├── stock           (integer) -- Total inventory
└── timestamps

holds (temporary reservations)
├── id              (primary key)
├── product_id      (foreign key → products)
├── quantity        (integer)
├── expires_at      (timestamp) -- 2 minutes from creation
├── status          (enum: active, expired, consumed)
└── timestamps
    Indexes: [status, expires_at], [product_id, status]

orders
├── id              (primary key)
├── hold_id         (foreign key → holds, unique)
├── product_id      (foreign key → products)
├── quantity        (integer)
├── total_price     (decimal)
├── status          (enum: pending, paid, cancelled)
└── timestamps
    Index: [status]

payment_webhooks (idempotency tracking)
├── id              (primary key)
├── idempotency_key (string, unique) -- Prevents duplicates
├── order_id        (foreign key → orders)
├── status          (enum: success, failure)
├── payload         (json)
├── processed_at    (timestamp)
└── timestamps
    Index: [order_id], [processed_at]
```

### Key Calculations

**Available Stock:**

```
Available = Total Stock - Active Holds - Paid Orders
```

**Example:**

```
Total Stock: 100
Active Holds: 20 (reserved, not yet ordered)
Paid Orders: 30 (sold)
Available: 100 - 20 - 30 = 50
```

---

## Quick Start

### Prerequisites

-   Docker & Docker Compose
-   Git
-   curl or Postman (for testing)

### Installation

```bash
# 1. Clone repository
git clone <repository-url>
cd flash-sale-api

# 2. Copy environment file
cp .env.example .env

# 3. Update .env for Docker
# Add these lines if not present:
WWWGROUP=1000
WWWUSER=1000
APP_PORT=8000

# 4. Start Docker containers
./vendor/bin/sail up -d

# 5. Install dependencies (if needed)
./vendor/bin/sail composer install

# 6. Generate application key
./vendor/bin/sail artisan key:generate

# 7. Run migrations
./vendor/bin/sail artisan migrate

# 8. Seed database with sample product
./vendor/bin/sail artisan db:seed --class=ProductSeeder

# 9. Clear caches
./vendor/bin/sail artisan optimize:clear

# 10. Start scheduler (in separate terminal)
./vendor/bin/sail artisan schedule:work
```

### Verify Installation

```bash
# Check containers are running
./vendor/bin/sail ps

# Test API
curl http://localhost:8000/api/test
# Expected: {"message":"API is working!","timestamp":"..."}

# View product
curl http://localhost:8000/api/products/1
# Expected: {"success":true,"data":{...}}
```

---

## API Documentation

### Base URL

```
http://localhost:8000/api
```

### Endpoints Summary

| Method | Endpoint            | Description                      |
| ------ | ------------------- | -------------------------------- |
| GET    | `/products/{id}`    | Get product with available stock |
| POST   | `/holds`            | Create temporary reservation     |
| POST   | `/orders`           | Create order from hold           |
| POST   | `/payments/webhook` | Process payment notification     |

### Complete Flow Example

```bash
# 1. View product
curl http://localhost:8000/api/products/1

# 2. Create hold (reserve 2 items for 2 minutes)
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 2}'
# Response: {"success":true,"data":{"hold_id":1,"expires_at":"..."}}

# 3. Create order (checkout)
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 1}'
# Response: {"success":true,"data":{"order_id":1,"status":"pending",...}}

# 4. Payment webhook (success)
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "unique-payment-123",
    "order_id": 1,
    "status": "success"
  }'
# Response: {"success":true,"data":{"order_status":"paid"}}
```

** For complete API documentation, see [API_DOCUMENTATION.md](API_DOCUMENTATION.md)**

---

## Testing

### Run All Tests

```bash
./vendor/bin/sail artisan test
```

### Test Coverage

**14 Tests | 48 Assertions**

1. **HoldConcurrencyTest** (3 tests)

    - Parallel holds prevent overselling
    - Multiple holds within stock succeed
    - Hold exceeding stock fails

2. **HoldExpiryTest** (4 tests)

    - Expired holds return availability
    - Active holds not expired
    - Multiple expired holds processed
    - Consumed holds not expired

3. **WebhookIdempotencyTest** (3 tests)

    - Duplicate webhooks are idempotent
    - Different webhooks process independently
    - Failed payment cancels order

4. **WebhookOrderRaceTest** (4 tests)
    - Webhook before order returns 202
    - Webhook processes when order exists
    - Concurrent order/webhook race
    - Multiple webhooks same order

### Manual Concurrency Test

```bash
# Simulate 50 concurrent users trying to buy last item
for i in {1..50}; do
  curl -X POST http://localhost:8000/api/holds \
    -H "Content-Type: application/json" \
    -d '{"product_id": 1, "qty": 1}' &
done
wait

# Verify only 1 succeeded
curl http://localhost:8000/api/products/1
```

** For detailed testing guide, see [TESTING.md](TESTING.md)**

---

## How It Works

### 1. Preventing Overselling

**Problem:** Two users trying to buy the last item simultaneously.

**Solution: Pessimistic Locking**

```php
DB::transaction(function () {
    // Lock the row - no other transaction can read/write
    $product = Product::lockForUpdate()->find($id);

    if ($product->available_stock >= $qty) {
        // Create hold
    }
    // Lock released when transaction ends
});
```

**Flow:**

```
User A: Locks row → Checks stock (1) → Creates hold → Unlocks
User B: Waits... → Locks row → Checks stock (0) → Error ✗
```

### 2. Hold Expiry System

**Problem:** User reserves items but doesn't complete checkout.

**Solution: Background Job**

```php
// Runs every minute
Schedule::command('holds:expire')
    ->everyMinute()
    ->withoutOverlapping();
```

**Timeline:**

```
0:00 → User creates hold (expires at 0:02)
0:01 → Hold still active
0:02 → Hold expires
0:03 → Background job runs → Hold marked as expired
      → Stock returned to availability
```

### 3. Idempotent Webhooks

**Problem:** Payment provider sends same webhook multiple times.

**Solution: Unique Key Constraint**

```php
payment_webhooks
├── idempotency_key (unique)  ← Database ensures uniqueness
```

**Flow:**

```
Webhook 1: key="abc123" → Process → Create record ✓
Webhook 2: key="abc123" → Database constraint → Return cached result ✓
                           (Stock NOT deducted again)
```

### 4. Out-of-Order Webhooks

**Problem:** Webhook arrives before order creation completes.

**Solution: Retry with Exponential Backoff**

```php
Attempt 1: Order not found → Wait 100ms
Attempt 2: Order not found → Wait 200ms
Attempt 3: Order not found → Wait 400ms
Attempt 4: Order not found → Wait 800ms
Attempt 5: Order not found → Return 202 Accepted
```

---

## Performance

### Caching Strategy

| Data            | Cache Duration | Invalidation         |
| --------------- | -------------- | -------------------- |
| Product details | 10 seconds     | On stock change      |
| Available stock | 5 seconds      | On hold/order/expiry |

### Database Optimization

-   Indexes on frequently queried columns
-   Composite indexes for multi-column queries
-   Foreign key constraints for data integrity
-   InnoDB engine for row-level locking

### Load Testing Results

```bash
# Install Apache Bench
sudo apt-get install apache2-utils

# Test: 1000 requests, 100 concurrent
ab -n 1000 -c 100 http://localhost:8000/api/products/1
```

**Expected Performance:**

-   Requests per second: 500+
-   Average response time: < 50ms
-   No failed requests
-   No overselling

---

## Logs & Monitoring

### View Real-Time Logs

```bash
# Laravel logs
./vendor/bin/sail artisan tail

# Or directly
tail -f storage/logs/laravel.log
```

### Key Metrics Logged

```json
{
  "event": "hold_created",
  "hold_id": 123,
  "product_id": 1,
  "quantity": 2,
  "expires_at": "2024-01-15T10:32:00Z"
}

{
  "event": "webhook_duplicate_detected",
  "idempotency_key": "unique-123",
  "order_id": 456
}

{
  "event": "deadlock_retry",
  "attempt": 2,
  "max_retries": 3
}
```

### Database Inspection

```bash
# Open MySQL
./vendor/bin/sail mysql

# Useful queries
USE laravel;

-- Active holds
SELECT * FROM holds WHERE status = 'active';

-- Recent orders
SELECT * FROM orders ORDER BY created_at DESC LIMIT 10;

-- Webhook processing
SELECT * FROM payment_webhooks ORDER BY created_at DESC;

-- Stock status
SELECT
  p.id,
  p.name,
  p.stock as total_stock,
  COUNT(DISTINCT CASE WHEN h.status = 'active' THEN h.id END) as active_holds,
  COUNT(DISTINCT CASE WHEN o.status = 'paid' THEN o.id END) as paid_orders
FROM products p
LEFT JOIN holds h ON p.id = h.product_id
LEFT JOIN orders o ON p.id = o.product_id
GROUP BY p.id;
```

---

## Troubleshooting

### Issue: Tests Failing

```bash
# Clear all caches
./vendor/bin/sail artisan optimize:clear

# Reset database
./vendor/bin/sail artisan migrate:fresh --seed

# Run tests
./vendor/bin/sail artisan test
```

### Issue: Routes Not Found (404)

```bash
# Clear route cache
./vendor/bin/sail artisan route:clear

# Verify routes exist
./vendor/bin/sail artisan route:list --path=api

# Check bootstrap/app.php has API routes enabled
```

### Issue: Stock Not Updating

```bash
# Clear cache manually
./vendor/bin/sail artisan cache:clear

# Check scheduler is running
ps aux | grep schedule

# Restart scheduler
./vendor/bin/sail artisan schedule:work
```

### Issue: Deadlocks Frequent

```bash
# View MySQL deadlocks
./vendor/bin/sail mysql -e "SHOW ENGINE INNODB STATUS\G" | grep -A 20 "LATEST DETECTED DEADLOCK"

# Increase retry attempts in HoldController
# Current: 3 attempts, consider increasing to 5
```

### Issue: Webhook Processing Slow

```bash
# Check webhook retry delays (exponential backoff)
# Current: 100ms → 200ms → 400ms → 800ms → 1600ms

# Monitor logs for out-of-order webhooks
grep "out-of-order" storage/logs/laravel.log
```

---

## Task Requirements Compliance

| Requirement                               | Status | Implementation                                              |
| ----------------------------------------- | ------ | ----------------------------------------------------------- |
| **Product endpoint with accurate stock**  |        | `GET /api/products/{id}` with real-time calculation + cache |
| **Create hold (2-min expiry)**            |        | `POST /api/holds` with automatic expiry via scheduled job   |
| **Holds reduce availability immediately** |        | Pessimistic locking in `Hold::createSafely()`               |
| **Auto-release on expiry**                |        | `ExpireHoldsCommand` runs every minute                      |
| **Create order from hold**                |        | `POST /api/orders` validates hold once                      |
| **Idempotent payment webhook**            |        | Unique `idempotency_key` constraint                         |
| **Out-of-order safe**                     |        | Retry logic with exponential backoff (5 attempts)           |
| **No overselling**                        |        | `lockForUpdate()` + database transactions                   |
| **Deadlock handling**                     |        | Retry with backoff (3 attempts)                             |
| **Fast under burst traffic**              |        | Strategic caching (10s product, 5s stock)                   |
| **Background expiry**                     |        | Scheduled command with `withoutOverlapping()`               |
| **Structured logging**                    |        | Comprehensive logging for all operations                    |
| **Automated tests**                       |        | 14 tests covering all scenarios                             |

### Test Coverage Matrix

| Test Scenario                    | Test Class               | Status |
| -------------------------------- | ------------------------ | ------ |
| Parallel holds at stock boundary | `HoldConcurrencyTest`    | Pass   |
| Hold expiry returns availability | `HoldExpiryTest`         | Pass   |
| Webhook idempotency (same key)   | `WebhookIdempotencyTest` | Pass   |
| Webhook before order creation    | `WebhookOrderRaceTest`   | Pass   |

---

## Project Structure

```
flash-sale-api/
├── app/
│   ├── Console/Commands/
│   │   └── ExpireHoldsCommand.php      # Background hold expiry
│   ├── Http/Controllers/Api/
│   │   ├── ProductController.php       # Product endpoint
│   │   ├── HoldController.php          # Hold creation
│   │   ├── OrderController.php         # Order creation
│   │   └── PaymentWebhookController.php # Webhook processing
│   └── Models/
│       ├── Product.php                 # Stock management
│       ├── Hold.php                    # Hold logic
│       ├── Order.php                   # Order state machine
│       └── PaymentWebhook.php          # Idempotency
├── database/
│   ├── migrations/                     # Database schema
│   └── seeders/ProductSeeder.php       # Sample data
├── routes/
│   ├── api.php                         # API endpoints
│   └── console.php                     # Scheduled tasks
├── tests/Feature/
│   ├── HoldConcurrencyTest.php
│   ├── HoldExpiryTest.php
│   ├── WebhookIdempotencyTest.php
│   └── WebhookOrderRaceTest.php
├── API_DOCUMENTATION.md                # Complete API docs
├── TESTING.md                          # Testing guide
└── README.md                           # This file
```

---

## Learning Resources

### Key Concepts Demonstrated

1. **Pessimistic Locking**

    - `SELECT ... FOR UPDATE`
    - Row-level locks in InnoDB
    - Transaction isolation

2. **Idempotency**

    - Unique constraints
    - Duplicate detection
    - Safe retries

3. **Concurrency Control**

    - Race conditions
    - Deadlock handling
    - Atomic operations

4. **Event-Driven Architecture**
    - Webhook processing
    - Out-of-order events
    - Eventual consistency

---

## Contributing

This is an interview task project. For any questions or improvements:

1. Check existing issues
2. Open a new issue with details
3. Submit pull request with tests

---

## License

This project is for interview assessment at GetPayIn

---

## Author

**Mohamed Elsayed Abdellhay**

-   GitHub: [@https://github.com/mohamedabdellhay]
-   Email: mohamedabdellhay1@gmail.com

---

## Acknowledgments

-   Built with **Laravel 12**
-   Powered by **MySQL 8.0** (InnoDB)
-   Containerized with **Docker** (Laravel Sail)
-   Caching via **Redis**
