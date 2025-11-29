<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use App\Models\PaymentWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Same webhook sent multiple times should process only once
     */
    public function test_duplicate_webhooks_are_idempotent(): void
    {

        // Setup: create product, hold, order
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 2,
            'expires_at' => now()->addMinutes(2),
            'status' => 'consumed',
        ]);

        $order = Order::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'total_price' => 200.00,
            'status' => 'pending',
        ]);

        $idempotencyKey = 'unique-test-key-123';

        // Send the webhook for the first time
        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        $response1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'order_status' => 'paid',
                ],
            ]);

        // Verify Order
        $order->refresh();
        $this->assertEquals('paid', $order->status);

        // Verify Stock
        $product->refresh();
        $this->assertEquals(8, $product->stock);

        // Send the same webhook again   (duplicate)
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        $response2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Webhook already processed',
            ]);

        // Stock should remain the same (not deducted again)
        $product->refresh();
        $this->assertEquals(8, $product->stock);

        // Order should remain paid
        $order->refresh();
        $this->assertEquals('paid', $order->status);

        // There should be only one webhook
        $webhookCount = PaymentWebhook::where('idempotency_key', $idempotencyKey)->count();
        $this->assertEquals(1, $webhookCount);
    }

    /**
     * Test: Different webhooks with different keys should process independently
     */
    public function test_different_webhooks_process_independently(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        // Create 2 different orders
        $hold1 = Hold::create([
            'product_id' => $product->id,
            'quantity' => 1,
            'expires_at' => now()->addMinutes(2),
            'status' => 'consumed',
        ]);

        $order1 = Order::create([
            'hold_id' => $hold1->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'total_price' => 100.00,
            'status' => 'pending',
        ]);

        $hold2 = Hold::create([
            'product_id' => $product->id,
            'quantity' => 2,
            'expires_at' => now()->addMinutes(2),
            'status' => 'consumed',
        ]);

        $order2 = Order::create([
            'hold_id' => $hold2->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'total_price' => 200.00,
            'status' => 'pending',
        ]);

        // Send webhook for order1 (success)
        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'key-order-1',
            'order_id' => $order1->id,
            'status' => 'success',
        ]);

        // Send webhook for order2 (failure)
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'key-order-2',
            'order_id' => $order2->id,
            'status' => 'failure',
        ]);

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Order 1 = paid
        $order1->refresh();
        $this->assertEquals('paid', $order1->status);

        // Order 2 = cancelled
        $order2->refresh();
        $this->assertEquals('cancelled', $order2->status);

        // Stock = 9 (10 - 1 from order1 only)
        $product->refresh();
        $this->assertEquals(9, $product->stock);

        // 2 webhooks in the database
        $this->assertEquals(2, PaymentWebhook::count());
    }

    /**
     * Test: Failed payment webhook should cancel order
     */
    public function test_failed_payment_cancels_order(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 3,
            'expires_at' => now()->addMinutes(2),
            'status' => 'consumed',
        ]);

        $order = Order::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 3,
            'total_price' => 300.00,
            'status' => 'pending',
        ]);

        // Send webhook with failure status
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'failed-payment-key',
            'order_id' => $order->id,
            'status' => 'failure',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'order_status' => 'cancelled',
                ],
            ]);

        // Order = cancelled
        $order->refresh();
        $this->assertEquals('cancelled', $order->status);

        // Stock should remain the same (not deducted)
        $product->refresh();
        $this->assertEquals(10, $product->stock);
    }
}
