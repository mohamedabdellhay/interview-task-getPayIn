<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Order extends Model
{
    protected $fillable = [
        'hold_id',
        'product_id',
        'quantity',
        'total_price',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'total_price' => 'decimal:2',
    ];

    // Relationships
    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function paymentWebhooks(): HasMany
    {
        return $this->hasMany(PaymentWebhook::class);
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * create Order from Hold
     */
    public static function createFromHold(int $holdId): ?self
    {
        return DB::transaction(function () use ($holdId) {
            // Lock the hold
            $hold = Hold::lockForUpdate()->find($holdId);

            if (!$hold) {
                throw new \Exception('Hold not found');
            }

            // Checking the validity of the Hold
            if (!$hold->isValid()) {
                throw new \Exception('Hold is invalid or expired');
            }

            // Checking if the Hold has not been used before
            if ($hold->order()->exists()) {
                throw new \Exception('Hold already used');
            }

            // Lock the product
            $product = Product::lockForUpdate()->find($hold->product_id);

            // Calculate the total price
            $totalPrice = $product->price * $hold->quantity;

            // Create the Order
            $order = self::create([
                'hold_id' => $hold->id,
                'product_id' => $product->id,
                'quantity' => $hold->quantity,
                'total_price' => $totalPrice,
                'status' => 'pending',
            ]);

            // Mark the Hold as consumed
            $hold->markAsConsumed();

            return $order;
        });
    }

    /**
     * Update the Order status to Paid
     */
    public function markAsPaid(): bool
    {
        return DB::transaction(function () {
            if ($this->status !== 'pending') {
                return false;
            }

            $this->update(['status' => 'paid']);

            // Decrement the actual stock
            $this->product->decrementStockSafely($this->quantity);

            return true;
        });
    }

    /**
     * Cancel the Order and return the stock
     */
    public function markAsCancelled(): bool
    {
        return DB::transaction(function () {
            if ($this->status !== 'pending') {
                return false;
            }

            $this->update(['status' => 'cancelled']);

            // Clear the cache to update available stock
            $this->product->clearAvailableStockCache();

            return true;
        });
    }
}
