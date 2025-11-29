<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Hold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class HoldExpiryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test: Expired holds return availability automatically
     */
    public function test_expired_holds_return_availability(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        // Create an active hold first  
        $expiredHold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => now()->addSeconds(1), // Expires after one second
            'status' => 'active',
        ]);

        // Before expiry: available stock = 5
        $product->clearAvailableStockCache();
        $this->assertEquals(5, $product->available_stock);

        // Wait for the hold to expire
        sleep(2);

        // Run the expiry command
        Artisan::call('holds:expire');

        // After expiry: available stock = 10
        $product->clearAvailableStockCache();
        $product->refresh();
        $this->assertEquals(10, $product->available_stock);

        // Verify hold status change    
        $expiredHold->refresh();
        $this->assertEquals('expired', $expiredHold->status);
    }

    /**
     * Test: Active holds are not affected by expiry command
     */
    public function test_active_holds_not_expired(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        // Create an active hold (in the future)
        $activeHold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 3,
            'expires_at' => now()->addMinutes(2),
            'status' => 'active',
        ]);

        // Run the expiry command
        Artisan::call('holds:expire');

        // Hold should remain active
        $activeHold->refresh();
        $this->assertEquals('active', $activeHold->status);

        // Available stock should still be 7
        $product->clearAvailableStockCache();
        $this->assertEquals(7, $product->available_stock);
    }

    /**
     * Test: Multiple expired holds are processed correctly
     */
    public function test_multiple_expired_holds_processed(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 20,
        ]);

        // Create 3 active holds that will expire soon
        Hold::create([
            'product_id' => $product->id,
            'quantity' => 5,
            'expires_at' => now()->addSeconds(1),
            'status' => 'active',
        ]);

        Hold::create([
            'product_id' => $product->id,
            'quantity' => 3,
            'expires_at' => now()->addSeconds(1),
            'status' => 'active',
        ]);

        Hold::create([
            'product_id' => $product->id,
            'quantity' => 2,
            'expires_at' => now()->addSeconds(1),
            'status' => 'active',
        ]);

        // Before expiry: available stock = 10 (20 - 5 - 3 - 2)
        $product->clearAvailableStockCache();
        $this->assertEquals(10, $product->available_stock);

        // Wait for all holds to expire
        sleep(2);

        // Run the expiry command
        Artisan::call('holds:expire');

        // After expiry: available stock = 20
        $product->clearAvailableStockCache();
        $this->assertEquals(20, $product->available_stock);

        // All holds are expired
        $expiredCount = Hold::where('product_id', $product->id)
            ->where('status', 'expired')
            ->count();

        $this->assertEquals(3, $expiredCount);
    }

    /**
     * Test: Consumed holds are not affected by expiry
     */
    public function test_consumed_holds_not_expired(): void
    {
        $product = Product::create([
            'name' => 'Test Product',
            'price' => 100.00,
            'stock' => 10,
        ]);

        // Create a consumed hold (used in an order)
        $consumedHold = Hold::create([
            'product_id' => $product->id,
            'quantity' => 3,
            'expires_at' => now()->subMinutes(5), // expired
            'status' => 'consumed', // but consumed
        ]);

        // Run the expiry command
        Artisan::call('holds:expire');

        // Hold should remain consumed (not expired)
        $consumedHold->refresh();
        $this->assertEquals('consumed', $consumedHold->status);
    }
}
