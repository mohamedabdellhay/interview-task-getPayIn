<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Hold;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookOrderRaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Webhook arriving before order creation should handle gracefully
     * 
     * This tests the "out-of-order" scenario where payment provider
     * sends webhook before our API finishes creating the order response
     */
    public function test_webhook_before_order_creation_returns_202(): void
    {
        // Attempting to send a webhook for a non-existent order

        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'early-webhook-key',
            'order_id' => 99999, // order does not exist
            'status' => 'success',
        ]);

        // Should return 202 Accepted after several attempts
        // (In reality, the retry logic will attempt 5 times)
        $response->assertStatus(202)
            ->assertJson([
                'success' => true,
                'message' => 'Webhook received, will process when order is ready',
            ]);
    }

    /**
     * Test: Webhook processing succeeds when order exists
     */
    public function test_webhook_processes_when_order_exists(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 1,
            'expires_at' => now()->addMinutes(2),
            'status' => 'consumed',
        ]);

        $order = Order::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'total_price' => 100.00,
            'status' => 'pending',
        ]);

        // Webhook arrives after order creation
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'normal-webhook-key',
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'order_status' => 'paid',
                ],
            ]);
    }

    /**
     * Test: Race condition simulation - webhook and order creation
     * 
     * Simulates: Client creates order, payment provider immediately
     * sends webhook, both processes race to update the order
     */
    public function test_concurrent_order_creation_and_webhook(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 2,
            'expires_at' => now()->addMinutes(2),
            'status' => 'active',
        ]);

        // Simulate: Creating order and webhook at the same time

        // 1. Create Order
        $orderResponse = $this->postJson('/api/orders', [
            'hold_id' => $hold->id,
        ]);

        $orderResponse->assertStatus(201);
        $orderId = $orderResponse->json('data.order_id');

        // 2. Send Webhook immediately (as if it arrived very quickly)
        $webhookResponse = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'race-condition-key',
            'order_id' => $orderId,
            'status' => 'success',
        ]);

        // The webhook should succeed
        $webhookResponse->assertStatus(200);

        // Order should be paid
        $order = Order::find($orderId);
        $this->assertEquals('paid', $order->status);

        // Stock should be 8
        $product->refresh();
        $this->assertEquals(8, $product->stock);
    }

    /**
     * Test: Multiple webhooks for same order with different keys
     * (should only first one succeed, others should be duplicate)
     */
    public function test_multiple_webhooks_same_order_different_keys(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        $hold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 1,
            'expires_at' => now()->addMinutes(2),
            'status' => 'consumed',
        ]);

        $order = Order::create([
            'hold_id' => $hold->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'total_price' => 100.00,
            'status' => 'pending',
        ]);

        // Send the first webhook (success)
        $response1 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'first-webhook',
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        $response1->assertStatus(200);

        // Order = paid
        $order->refresh();
        $this->assertEquals('paid', $order->status);

        // Attempt to send a second webhook with a different key (but same order)
        $response2 = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'second-webhook',
            'order_id' => $order->id,
            'status' => 'success',
        ]);

        // It should succeed (order status should not change)
        $response2->assertStatus(200);

        // Order should still be paid
        $order->refresh();
        $this->assertEquals('paid', $order->status);

        // Stock should be deducted only once
        $product->refresh();
        $this->assertEquals(9, $product->stock);
    }
}
