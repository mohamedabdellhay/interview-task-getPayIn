<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    /**
     * POST /api/orders
     * 
     * إنشاء طلب من حجز صالح
     * كل hold يمكن استخدامه مرة واحدة فقط
     */
    public function store(Request $request): JsonResponse
    {
        // Validation
        $validator = Validator::make($request->all(), [
            'hold_id' => 'required|integer|exists:holds,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $holdId = $request->input('hold_id');

        try {
            $order = Order::createFromHold($holdId);

            Log::info('Order created successfully', [
                'order_id' => $order->id,
                'hold_id' => $holdId,
                'product_id' => $order->product_id,
                'quantity' => $order->quantity,
                'total_price' => $order->total_price,
                'status' => $order->status,
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'order_id' => $order->id,
                    'product_id' => $order->product_id,
                    'quantity' => $order->quantity,
                    'total_price' => $order->total_price,
                    'status' => $order->status,
                    'created_at' => $order->created_at->toIso8601String(),
                ],
            ], 201);
        } catch (\Exception $e) {
            // تحديد نوع الخطأ وإرجاع رسالة مناسبة
            $message = $e->getMessage();
            $statusCode = 400;

            if (str_contains($message, 'Hold not found')) {
                $statusCode = 404;
                $message = 'Hold not found';
            } elseif (str_contains($message, 'invalid or expired')) {
                $statusCode = 410; // 410 Gone
                $message = 'Hold has expired or is invalid';
            } elseif (str_contains($message, 'already used')) {
                $statusCode = 409; // 409 Conflict
                $message = 'Hold has already been used';
            }

            Log::warning('Order creation failed', [
                'hold_id' => $holdId,
                'error' => $message,
            ]);

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $statusCode);
        }
    }
}
