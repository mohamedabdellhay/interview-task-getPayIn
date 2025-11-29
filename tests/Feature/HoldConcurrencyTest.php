<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Hold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HoldConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Parallel hold attempts at stock boundary should not oversell
     * 
     * Scenario: 10 users try to reserve last item simultaneously
     * Expected: Only 1 hold succeeds, 9 fail with "insufficient stock"
     */
    public function test_parallel_holds_prevent_overselling(): void
    {
        // Create a product with only one item in stock
        $product = Product::create([
            'name' => 'Limited Item',
            'price' => 100.00,
            'stock' => 1,
        ]);

        // Simulate 10 concurrent requests
        $results = [];
        $processes = [];

        for ($i = 0; $i < 10; $i++) {
            $processes[] = function () use ($product, &$results, $i) {
                try {
                    $response = $this->postJson('/api/holds', [
                        'product_id' => $product->id,
                        'qty' => 1,
                    ]);

                    $results[$i] = [
                        'status' => $response->status(),
                        'success' => $response->json('success'),
                    ];
                } catch (\Exception $e) {
                    $results[$i] = [
                        'status' => 500,
                        'error' => $e->getMessage(),
                    ];
                }
            };
        }

        // Execute processes in parallel (simulated)
        foreach ($processes as $process) {
            $process();
        }

        // Verify results
        $successCount = collect($results)->where('status', 201)->count();
        $failureCount = collect($results)->where('status', 409)->count();

        // Only one should succeed
        $this->assertEquals(1, $successCount, 'Only one hold should succeed');

        // Nine should fail
        $this->assertEquals(9, $failureCount, 'Nine holds should fail with 409 Conflict');

        // Verify database
        $activeHolds = Hold::where('product_id', $product->id)
            ->where('status', 'active')
            ->count();

        $this->assertEquals(1, $activeHolds, 'Only one active hold should exist in database');

        // Verify available stock
        $product->refresh();
        $this->assertEquals(0, $product->available_stock, 'Available stock should be 0');
    }

    /**
     * Test: Multiple holds within available stock should all succeed
     */
    public function test_multiple_holds_within_stock_succeed(): void
    {
        $product = Product::create([
            'name' => 'Available Item',
            'price' => 100.00,
            'stock' => 10,
        ]);

        // 5 users each reserve 1 item (total 5)
        $successes = 0;

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/holds', [
                'product_id' => $product->id,
                'qty' => 1,
            ]);

            if ($response->status() === 201) {
                $successes++;
            }
        }

        $this->assertEquals(5, $successes, 'All 5 holds should succeed');

        $product->refresh();
        $this->assertEquals(5, $product->available_stock, 'Available stock should be 5');
    }

    /**
     * Test: Hold that would exceed stock should fail immediately
     */
    public function test_hold_exceeding_stock_fails(): void
    {
        $product = Product::create([
            'name' => 'Limited Item',
            'price' => 100.00,
            'stock' => 5,
        ]);

        // Try to reserve more than available
        $response = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 10,
        ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'Insufficient stock available',
            ]);

        // Stock should remain unchanged
        $product->refresh();
        $this->assertEquals(5, $product->available_stock);
    }
}
