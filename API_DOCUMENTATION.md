# Flash Sale API Documentation

## Base URL

```
http://localhost:8000/api
```

## Overview

This API handles flash-sale checkout operations with high concurrency support, preventing overselling through pessimistic locking and implementing idempotent payment webhooks.

---

## Endpoints

### 1. Get Product Details

Retrieves product information including real-time available stock.

**Endpoint:** `GET /api/products/{id}`

**Parameters:**

-   `id` (path, required) - Product ID

**Response: 200 OK**

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "Limited Edition iPhone 16 Pro",
        "price": "1299.99",
        "stock": 100,
        "available_stock": 85
    }
}
```

**Response: 404 Not Found**

```json
{
    "success": false,
    "message": "Product not found"
}
```

**Example:**

```bash
curl http://localhost:8000/api/products/1
```

**Notes:**

-   `stock`: Total inventory
-   `available_stock`: Current available stock (Total - Active Holds - Paid Orders)
-   Response is cached for 10 seconds for performance

---

### 2. Create Hold

Creates a temporary reservation (2-minute expiry) for a product.

**Endpoint:** `POST /api/holds`

**Request Body:**

```json
{
    "product_id": 1,
    "qty": 2
}
```

**Parameters:**

-   `product_id` (integer, required) - Product ID
-   `qty` (integer, required) - Quantity (1-10)

**Response: 201 Created**

```json
{
    "success": true,
    "data": {
        "hold_id": 123,
        "expires_at": "2024-01-15T10:32:00.000000Z"
    }
}
```

**Response: 409 Conflict** (Insufficient Stock)

```json
{
    "success": false,
    "message": "Insufficient stock available"
}
```

**Response: 422 Unprocessable Entity** (Validation Error)

```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "product_id": ["The product id field is required."],
        "qty": ["The qty must be between 1 and 10."]
    }
}
```

**Response: 503 Service Unavailable** (High Traffic/Deadlock)

```json
{
    "success": false,
    "message": "Unable to create hold due to high traffic, please try again"
}
```

**Example:**

```bash
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": 1,
    "qty": 2
  }'
```

**Notes:**

-   Holds expire after 2 minutes
-   Hold immediately reduces `available_stock` for other users
-   Implements retry logic with exponential backoff for deadlock handling (3 attempts)
-   Prevents overselling using pessimistic locking

---

### 3. Create Order

Creates an order from a valid hold.

**Endpoint:** `POST /api/orders`

**Request Body:**

```json
{
    "hold_id": 123
}
```

**Parameters:**

-   `hold_id` (integer, required) - Hold ID from previous hold creation

**Response: 201 Created**

```json
{
    "success": true,
    "data": {
        "order_id": 456,
        "product_id": 1,
        "quantity": 2,
        "total_price": "2599.98",
        "status": "pending",
        "created_at": "2024-01-15T10:30:00.000000Z"
    }
}
```

**Response: 404 Not Found**

```json
{
    "success": false,
    "message": "Hold not found"
}
```

**Response: 409 Conflict** (Hold Already Used)

```json
{
    "success": false,
    "message": "Hold has already been used"
}
```

**Response: 410 Gone** (Hold Expired)

```json
{
    "success": false,
    "message": "Hold has expired or is invalid"
}
```

**Example:**

```bash
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{
    "hold_id": 123
  }'
```

**Notes:**

-   Each hold can only be used once
-   Hold must be valid and not expired
-   Order is created in `pending` status awaiting payment
-   Hold is marked as `consumed` after order creation

---

### 4. Payment Webhook (Idempotent)

Processes payment notifications from payment providers.

**Endpoint:** `POST /api/payments/webhook`

**Request Body:**

```json
{
    "idempotency_key": "unique-payment-123",
    "order_id": 456,
    "status": "success",
    "amount": 2599.98,
    "payment_method": "credit_card"
}
```

**Parameters:**

-   `idempotency_key` (string, required) - Unique key to prevent duplicate processing
-   `order_id` (integer, required) - Order ID
-   `status` (string, required) - Payment status: `success` or `failure`
-   `amount` (numeric, optional) - Payment amount
-   `payment_method` (string, optional) - Payment method used

**Response: 200 OK** (Success or Duplicate)

```json
{
    "success": true,
    "message": "Payment successful - order marked as paid",
    "data": {
        "webhook_id": 789,
        "order_status": "paid"
    }
}
```

**Response: 200 OK** (Duplicate Webhook)

```json
{
    "success": true,
    "message": "Webhook already processed",
    "data": {
        "webhook_id": 789,
        "order_status": "paid"
    }
}
```

**Response: 202 Accepted** (Out-of-Order Webhook)

```json
{
    "success": true,
    "message": "Webhook received, will process when order is ready"
}
```

**Response: 422 Unprocessable Entity** (Validation Error)

```json
{
    "success": false,
    "message": "Validation failed",
    "errors": {
        "status": ["The status field must be either success or failure."]
    }
}
```

**Example: Successful Payment**

```bash
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "payment-unique-123",
    "order_id": 456,
    "status": "success",
    "amount": 2599.98
  }'
```

**Example: Failed Payment**

```bash
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "payment-unique-456",
    "order_id": 789,
    "status": "failure"
  }'
```

**Notes:**

-   **Idempotent**: Same `idempotency_key` can be sent multiple times safely
-   **Out-of-Order Safe**: Handles webhooks arriving before order creation (retries 5 times with exponential backoff)
-   **Status Updates**:
    -   `success` → Order status becomes `paid`, stock decremented
    -   `failure` → Order status becomes `cancelled`, availability restored
-   Retry delays: 100ms → 200ms → 400ms → 800ms → 1600ms
-   After 5 failed retries, returns `202 Accepted` for async processing

---

## Complete Flow Example

### Scenario: User buys 2 iPhones

```bash
# 1. View product and check availability
curl http://localhost:8000/api/products/1
# Response: available_stock = 100

# 2. Create a hold (reserve 2 items for 2 minutes)
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "qty": 2}'
# Response: { "hold_id": 123, "expires_at": "..." }

# 3. Check availability again (should decrease)
curl http://localhost:8000/api/products/1
# Response: available_stock = 98 (reserved by hold)

# 4. Create order (checkout)
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 123}'
# Response: { "order_id": 456, "status": "pending", ... }

# 5. Payment provider sends webhook (success)
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "unique-payment-123",
    "order_id": 456,
    "status": "success"
  }'
# Response: { "order_status": "paid" }

# 6. Check stock again (should decrease permanently)
curl http://localhost:8000/api/products/1
# Response: stock = 98, available_stock = 98
```

---

## Error Handling

### HTTP Status Codes

-   `200 OK` - Success
-   `201 Created` - Resource created
-   `202 Accepted` - Request accepted for async processing
-   `400 Bad Request` - Invalid request
-   `404 Not Found` - Resource not found
-   `409 Conflict` - Conflict (e.g., insufficient stock, hold already used)
-   `410 Gone` - Resource expired (e.g., hold expired)
-   `422 Unprocessable Entity` - Validation error
-   `500 Internal Server Error` - Server error
-   `503 Service Unavailable` - Service temporarily unavailable (high traffic)

### Common Error Responses

All error responses follow this format:

```json
{
    "success": false,
    "message": "Error description",
    "error": "Detailed error message (optional)"
}
```

---

## Concurrency & Safety Features

### Overselling Prevention

-   Pessimistic locking (`SELECT ... FOR UPDATE`)
-   Atomic operations in database transactions
-   Real-time stock calculation

### Deadlock Handling

-   Automatic retry with exponential backoff
-   Maximum 3 retry attempts for hold creation
-   Graceful degradation under high load

### Idempotency

-   Unique `idempotency_key` prevents duplicate webhook processing
-   Safe to retry failed requests
-   Consistent final state guaranteed

### Hold Expiry

-   Automatic expiry after 2 minutes
-   Background job releases expired holds (runs every minute)
-   Availability immediately restored on expiry

---

## Performance Optimizations

### Caching

-   Product details cached for 10 seconds
-   Available stock cached for 5 seconds
-   Cache invalidation on stock changes

### Database Indexes

-   Indexed foreign keys for fast joins
-   Composite indexes on frequently queried columns
-   Optimized for high-concurrency scenarios

---

## Testing

### Manual Testing with curl

See examples above for each endpoint.

### Load Testing

```bash
# Install Apache Bench (if not installed)
sudo apt-get install apache2-utils

# Test product endpoint (100 concurrent requests)
ab -n 1000 -c 100 http://localhost:8000/api/products/1

# Test hold creation (simulate flash sale)
ab -n 500 -c 50 -p hold.json -T application/json \
  http://localhost:8000/api/holds
```

### Automated Tests

```bash
./vendor/bin/sail artisan test
```

---

## Logging & Monitoring

### Log Locations

-   Application logs: `storage/logs/laravel.log`
-   Database queries: Enable query logging in `.env`

### Key Metrics Logged

-   Hold creation success/failure
-   Deadlock occurrences and retries
-   Webhook duplicate detection
-   Out-of-order webhook handling
-   Stock availability changes

### Log Example

```json
{
    "level": "info",
    "message": "Hold created successfully",
    "context": {
        "hold_id": 123,
        "product_id": 1,
        "quantity": 2,
        "expires_at": "2024-01-15T10:32:00.000000Z"
    }
}
```

---

## Database Schema

### Products

-   `id`, `name`, `price`, `stock`, `timestamps`

### Holds

-   `id`, `product_id`, `quantity`, `expires_at`, `status`, `timestamps`
-   Status: `active`, `expired`, `consumed`

### Orders

-   `id`, `hold_id` (unique), `product_id`, `quantity`, `total_price`, `status`, `timestamps`
-   Status: `pending`, `paid`, `cancelled`

### Payment Webhooks

-   `id`, `idempotency_key` (unique), `order_id`, `status`, `payload`, `processed_at`, `timestamps`

---

## Rate Limiting

Currently no rate limiting is implemented. For production:

-   Recommended: 100 requests/minute per IP
-   Use Laravel's built-in rate limiting or external services (Redis, Cloudflare)

---

## Security Considerations

### For Production

1. **Authentication**: Add API token authentication
2. **HTTPS**: Always use HTTPS in production
3. **Webhook Verification**: Verify webhook signatures from payment providers
4. **Rate Limiting**: Implement rate limiting per IP/user
5. **Input Sanitization**: Already implemented via Laravel validation
6. **CORS**: Configure CORS policy if needed

---

## Support & Contact

For issues or questions, please contact the development team.
