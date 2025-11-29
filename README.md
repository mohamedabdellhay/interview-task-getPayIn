# Flash-Sale Checkout API

A high-concurrency Laravel API for flash-sale checkout operations with robust overselling prevention, idempotent payment webhooks, and automatic hold expiry management.

## Features

-   **Overselling Prevention**: Pessimistic locking prevents race conditions
-   **Temporary Holds**: 2-minute reservations with automatic expiry
-   **Idempotent Webhooks**: Safe duplicate webhook processing
-   **Out-of-Order Handling**: Webhooks can arrive before order creation
-   **Deadlock Recovery**: Automatic retry with exponential backoff
-   **High Performance**: Strategic caching for burst traffic
-   **Comprehensive Logging**: Detailed metrics for monitoring

## Quick Start

### Prerequisites

-   Docker & Docker Compose
-   Git

### Installation

```bash
# 1. Clone the repository
git clone <repository-url>
cd flash-sale-api

# 2. Copy environment file
cp .env.example .env

# 3. Start Docker containers
./vendor/bin/sail up -d

# 4. Install dependencies
./vendor/bin/sail composer install

# 5. Run migrations
./vendor/bin/sail artisan migrate

# 6. Seed database with sample product
./vendor/bin/sail artisan db:seed --class=ProductSeeder

# 7. Clear cache
./vendor/bin/sail artisan optimize:clear
```

### Test the API

```bash
# View product
curl http://localhost:8000/api/products/1

# Create hold
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 2}'

# Create order (use hold_id from previous response)
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 1}'

# Send payment webhook (use order_id from previous response)
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "unique-key-123",
    "order_id": 1,
    "status": "success"
  }'
```

## Documentation

See [API_DOCUMENTATION.md](API_DOCUMENTATION.md) for complete API reference.

## Architecture

### Database Schema

```
products
├── id
├── name
├── price
└── stock

holds (temporary reservations)
├── id
├── product_id
├── quantity
├── expires_at
└── status (active/expired/consumed)

orders
├── id
├── hold_id (unique)
├── product_id
├── quantity
├── total_price
└── status (pending/paid/cancelled)

payment_webhooks (idempotency tracking)
├── id
├── idempotency_key (unique)
├── order_id
├── status
└── processed_at
```

### Core Concepts

#### 1. Available Stock Calculation

```
Available Stock = Total Stock - Active Holds - Paid Orders
```

#### 2. Hold Lifecycle

```
Create → Active (2 min) → Expired/Consumed
```

#### 3. Order State Machine

```
Pending → Paid (on webhook success)
        → Cancelled (on webhook failure)
```

## Concurrency Safety

### Pessimistic Locking

```php
DB::transaction(function () {
    $product = Product::lockForUpdate()->find($id);
    // No other transaction can read/write this row
});
```

### Deadlock Handling

-   Automatic retry (3 attempts)
-   Exponential backoff: 100ms → 200ms → 300ms
-   Graceful degradation under high load

### Idempotency

-   Unique `idempotency_key` prevents duplicate processing
-   Database constraint ensures single processing
-   Safe to retry failed webhooks

## Testing

### Run Tests

```bash
./vendor/bin/sail artisan test
```

### Load Testing

```bash
# Install Apache Bench
sudo apt-get install apache2-utils

# Test with 100 concurrent requests
ab -n 1000 -c 100 http://localhost:8000/api/products/1
```

### Manual Concurrency Test

```bash
# Create 10 parallel hold requests
for i in {1..10}; do
  curl -X POST http://localhost:8000/api/holds \
    -H "Content-Type: application/json" \
    -d '{"product_id": 1, "qty": 1}' &
done
wait

# Check available stock (should prevent overselling)
curl http://localhost:8000/api/products/1
```

## Monitoring & Logs

### View Logs

```bash
./vendor/bin/sail artisan tail
```

### Key Metrics Logged

-   Hold creation success/failure
-   Deadlock occurrences
-   Webhook duplicate detection
-   Out-of-order webhook handling
-   Stock changes

### Log Location

```
storage/logs/laravel.log
```

## Development

### Code Structure

```
app/
├── Models/
│   ├── Product.php          # Stock management & caching
│   ├── Hold.php             # Temporary reservations
│   ├── Order.php            # Order state machine
│   └── PaymentWebhook.php   # Idempotent processing
└── Http/Controllers/Api/
    ├── ProductController.php
    ├── HoldController.php
    ├── OrderController.php
    └── PaymentWebhookController.php
```

### Key Design Decisions

1. **Pessimistic Locking over Optimistic**

    - Simpler to reason about
    - Better for high-contention scenarios
    - MySQL InnoDB handles it well

2. **2-Minute Hold Expiry**

    - Balance between user experience and stock availability
    - Automatic cleanup via background job

3. **Cache Strategy**

    - Product details: 10 seconds
    - Available stock: 5 seconds
    - Invalidate on any stock change

4. **Retry Logic**
    - Holds: 3 attempts (deadlock recovery)
    - Webhooks: 5 attempts (out-of-order handling)
    - Exponential backoff prevents thundering herd

## Background Jobs

### Hold Expiry Job

Runs every minute to release expired holds:

```bash
# Start scheduler
./vendor/bin/sail artisan schedule:work
```

### Queue Worker (if needed)

```bash
./vendor/bin/sail artisan queue:work
```

## Configuration

### Environment Variables

```env
# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=sail
DB_PASSWORD=password

# Cache (redis/database/file)
CACHE_STORE=redis

# Queue (redis/database/sync)
QUEUE_CONNECTION=redis
```

## Task Requirements Compliance

| Requirement                          | Status | Implementation                 |
| ------------------------------------ | ------ | ------------------------------ |
| Product endpoint with accurate stock |        | Cached + real-time calculation |
| Create hold (2-min expiry)           |        | Auto-expiry via scheduled job  |
| Create order from hold               |        | Single-use validation          |
| Idempotent payment webhook           |        | Unique key constraint          |
| No overselling                       |        | Pessimistic locking            |
| Deadlock handling                    |        | Retry with backoff             |
| Fast under burst traffic             |        | Strategic caching              |
| Background expiry                    |        | Scheduled command              |
| Structured logging                   |        | Comprehensive logging          |

## Troubleshooting

### Routes not working

```bash
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan optimize:clear
```

### Database connection issues

```bash
# Check containers are running
./vendor/bin/sail ps

# Verify database exists
./vendor/bin/sail mysql -e "SHOW DATABASES;"
```

### Cache not updating

```bash
./vendor/bin/sail artisan cache:clear
```

## License

This project is for interview assessment purposes.

## Author

[Your Name]

## Acknowledgments

Built with Laravel 12, MySQL, and Redis.
