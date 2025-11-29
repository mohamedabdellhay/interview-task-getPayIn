<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class Product extends Model
{
    protected $fillable = ['name', 'price', 'stock'];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Calculate available stock (Stock - Active Holds)
     * With performance cache
     */
    public function getAvailableStockAttribute(): int
    {
        $cacheKey = "product:{$this->id}:available_stock";

        return Cache::remember($cacheKey, now()->addSeconds(5), function () {
            // Total stock
            $totalStock = $this->stock;

            // Reserved stock in active holds
            $reservedStock = $this->holds()
                ->where('status', 'active')
                ->where('expires_at', '>', now())
                ->sum('quantity');

            // Actually sold quantity (paid orders)
            $soldStock = $this->orders()
                ->where('status', 'paid')
                ->sum('quantity');

            return max(0, $totalStock - $reservedStock - $soldStock);
        });
    }

    /**
     * Clear the cache when the stock changes
     */
    public function clearAvailableStockCache(): void
    {
        Cache::forget("product:{$this->id}:available_stock");
    }

    /**
     * Update the stock safely (with locking)
     */
    public function decrementStockSafely(int $quantity): bool
    {
        return DB::transaction(function () use ($quantity) {
            // Pessimistic Lock
            $product = self::lockForUpdate()->find($this->id);

            if ($product->stock >= $quantity) {
                $product->decrement('stock', $quantity);
                $product->clearAvailableStockCache();
                return true;
            }

            return false;
        });
    }
}
