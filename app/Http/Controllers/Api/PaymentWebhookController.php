<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentWebhookController extends Controller
{
    /**
     * POST /api/payments/webhook
     * 
     * معالجة webhook من بوابة الدفع
     * 
     * المتطلبات:
     * - Idempotent: نفس الـ webhook يمكن أن يصل عدة مرات
     * - Out-of-order safe: الـ webhook قد يصل قبل إنشاء الـ Order
     * - يجب تحديث حالة الـ Order بشكل صحيح
     */
    public function handle(Request $request): JsonResponse
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'idempotency_key' => 'required|string|max:255',
            'order_id' => 'required|integer',
            'status' => 'required|in:success,failure',
            'amount' => 'nullable|numeric',
            'payment_method' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $idempotencyKey = $request->input('idempotency_key');
        $orderId = $request->input('order_id');
        $status = $request->input('status');

        // تخزين الـ payload كامل للـ debugging
        $payload = $request->all();

        Log::info('Webhook received', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'status' => $status,
        ]);

        // محاولة معالجة الـ webhook مع retry في حالة فشل مؤقت
        $maxRetries = 5;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $result = PaymentWebhook::processIdempotent(
                    idempotencyKey: $idempotencyKey,
                    orderId: $orderId,
                    status: $status,
                    payload: $payload
                );

                Log::info('Webhook processed', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'result' => $result,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => $result['message'],
                    'data' => [
                        'webhook_id' => $result['webhook_id'],
                        'order_status' => $result['order_status'],
                    ],
                ], 200);
            } catch (\Exception $e) {
                $attempt++;
                $errorMessage = $e->getMessage();

                // حالة خاصة: الـ Order لم يتم إنشاؤه بعد (out-of-order webhook)
                if (str_contains($errorMessage, 'Order not found')) {

                    Log::warning('Webhook arrived before order creation', [
                        'idempotency_key' => $idempotencyKey,
                        'order_id' => $orderId,
                        'attempt' => $attempt,
                    ]);

                    if ($attempt >= $maxRetries) {
                        // بعد عدة محاولات، نرجع 202 Accepted
                        // معناها: استلمنا الـ webhook، سنعالجه لاحقاً
                        Log::error('Webhook processing failed after retries', [
                            'idempotency_key' => $idempotencyKey,
                            'order_id' => $orderId,
                            'error' => 'Order still not found',
                        ]);

                        return response()->json([
                            'success' => true,
                            'message' => 'Webhook received, will process when order is ready',
                        ], 202); // 202 Accepted
                    }

                    // انتظار قصير قبل المحاولة التالية
                    // تزايد تصاعدي: 100ms, 200ms, 400ms, 800ms, 1600ms
                    usleep(100000 * pow(2, $attempt - 1));
                    continue;
                }

                // أي خطأ آخر
                Log::error('Webhook processing error', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'error' => $errorMessage,
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to process webhook',
                    'error' => $errorMessage,
                ], 500);
            }
        }

        // المفروض ما نوصلش هنا
        return response()->json([
            'success' => false,
            'message' => 'Webhook processing timeout',
        ], 503);
    }
}
