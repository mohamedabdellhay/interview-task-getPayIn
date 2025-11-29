<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Hold extends Model
{
    protected $fillable = [
        'product_id',
        'quantity',
        'expires_at',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expires_at' => 'datetime',
    ];

    // Relationships
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /**
     * Scopes for heavy queries
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'active')
            ->where('expires_at', '<=', now());
    }

    /**
     * Checking the validity of the Hold
     */
    public function isValid(): bool
    {
        return $this->status === 'active'
            && $this->expires_at > now();
    }

    /**
     * Mark the Hold as expired (returning the stock to available)
     */
    public function markAsExpired(): bool
    {
        return DB::transaction(function () {
            if ($this->status !== 'active') {
                return false;
            }

            $this->update(['status' => 'expired']);

            // Clear the cache to update available stock
            $this->product->clearAvailableStockCache();

            return true;
        });
    }

    /**
     * Mark the Hold as consumed (used in order)
     */
    public function markAsConsumed(): bool
    {
        return DB::transaction(function () {
            if ($this->status !== 'active' || !$this->isValid()) {
                return false;
            }

            $this->update(['status' => 'consumed']);

            // Clear the cache to update available stock
            $this->product->clearAvailableStockCache();

            return true;
        });
    }

    /**
     * Create a new Hold safely (with stock verification)
     */
    public static function createSafely(int $productId, int $quantity): ?self
    {
        return DB::transaction(function () use ($productId, $quantity) {
            // Lock the product for reading and writing
            $product = Product::lockForUpdate()->find($productId);

            if (!$product) {
                throw new \Exception('Product not found');
            }

            // Verify available stock
            if ($product->available_stock < $quantity) {
                throw new \Exception('Insufficient stock available');
            }

            // Create the hold
            $hold = self::create([
                'product_id' => $productId,
                'quantity' => $quantity,
                'expires_at' => now()->addMinutes(2), // 2 minutes
                'status' => 'active',
            ]);

            // Clear the cache to update available stock    
            $product->clearAvailableStockCache();

            return $hold;
        });
    }
}
