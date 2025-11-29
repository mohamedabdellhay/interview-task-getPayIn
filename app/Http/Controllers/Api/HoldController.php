<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hold;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class HoldController extends Controller
{
    /**
     * POST /api/holds
     * 
     * إنشاء حجز مؤقت (دقيقتين)
     * يجب أن يمنع overselling حتى مع طلبات متزامنة
     */
    public function store(Request $request): JsonResponse
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|integer|min:1|max:10', // حد أقصى 10 قطع للحجز الواحد
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $productId = $request->input('product_id');
        $quantity = $request->input('qty');

        // محاولة إنشاء الحجز مع retry في حالة deadlock
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                $hold = Hold::createSafely($productId, $quantity);

                Log::info('Hold created successfully', [
                    'hold_id' => $hold->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'expires_at' => $hold->expires_at,
                ]);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'hold_id' => $hold->id,
                        'expires_at' => $hold->expires_at->toIso8601String(),
                    ],
                ], 201);
            } catch (\Exception $e) {
                $attempt++;

                // التحقق من نوع الخطأ
                if (str_contains($e->getMessage(), 'Insufficient stock')) {
                    // مفيش مخزون كافي - مفيش retry
                    Log::warning('Hold creation failed - insufficient stock', [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'error' => $e->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient stock available',
                    ], 409); // 409 Conflict

                } elseif (str_contains($e->getMessage(), 'Deadlock')) {
                    // Deadlock - نحاول تاني
                    Log::warning('Deadlock detected, retrying...', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                    ]);

                    if ($attempt >= $maxRetries) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Unable to create hold due to high traffic, please try again',
                        ], 503); // 503 Service Unavailable
                    }

                    // انتظار قصير قبل المحاولة التالية
                    usleep(100000 * $attempt); // 100ms, 200ms, 300ms
                    continue;
                } else {
                    // خطأ غير متوقع
                    Log::error('Hold creation failed', [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'An error occurred while creating hold',
                        'error' => $e->getMessage(),
                    ], 500);
                }
            }
        }

        // المفروض ما نوصلش هنا
        return response()->json([
            'success' => false,
            'message' => 'Unable to create hold',
        ], 500);
    }
}
