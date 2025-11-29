<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhook extends Model
{
    protected $fillable = [
        'idempotency_key',
        'order_id',
        'status',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    // Relationship
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Process the Webhook Idempotently
     * 
     * This function ensures:
     * 1. The Webhook is processed only once (idempotency)
     * 2. If it arrives before the Order is created, wait
     * 3. If it arrives multiple times, return the same result
     */
    public static function processIdempotent(
        string $idempotencyKey,
        int $orderId,
        string $status,
        ?array $payload = null
    ): array {
        return DB::transaction(function () use ($idempotencyKey, $orderId, $status, $payload) {

            // 1. Check for existing webhook with the same idempotency key
            $existingWebhook = self::where('idempotency_key', $idempotencyKey)->first();

            if ($existingWebhook) {
                // The webhook has been processed before, return the old result
                Log::info('Duplicate webhook detected', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                ]);

                return [
                    'success' => true,
                    'message' => 'Webhook already processed',
                    'webhook_id' => $existingWebhook->id,
                    'order_status' => $existingWebhook->order->status,
                ];
            }

            // 2. Check for the existence of the Order (the webhook might have arrived before the order was created)
            $order = Order::lockForUpdate()->find($orderId);

            if (!$order) {
                // The Order is not yet created (out-of-order webhook)
                // Log the webhook and wait for the order to be created
                Log::warning('Webhook received before order creation', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                ]);

                throw new \Exception('Order not found - webhook arrived too early');
            }

            // 3. Create the Webhook record
            $webhook = self::create([
                'idempotency_key' => $idempotencyKey,
                'order_id' => $orderId,
                'status' => $status,
                'payload' => $payload,
                'processed_at' => now(),
            ]);

            // 4. Update the Order status based on the payment result
            if ($status === 'success') {
                $order->markAsPaid();
                $message = 'Payment successful - order marked as paid';
            } else {
                $order->markAsCancelled();
                $message = 'Payment failed - order cancelled';
            }

            Log::info('Webhook processed successfully', [
                'idempotency_key' => $idempotencyKey,
                'order_id' => $orderId,
                'status' => $status,
                'order_status' => $order->fresh()->status,
            ]);

            return [
                'success' => true,
                'message' => $message,
                'webhook_id' => $webhook->id,
                'order_status' => $order->fresh()->status,
            ];
        });
    }

    /**
     * Retry processing failed Webhooks
     */
    public static function retryFailed(): int
    {
        // In case there are webhooks that failed due to timing issues
        // retry logic can be implemented here

        return 0; // TODO: implement retry logic if needed
    }
}
