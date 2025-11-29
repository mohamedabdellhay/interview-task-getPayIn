<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    /**
     * GET /api/products/{id}
     * 
     * عرض تفاصيل المنتج مع المخزون المتاح
     * يجب أن يكون سريع جداً حتى مع burst traffic
     */
    public function show(int $id): JsonResponse
    {
        try {
            // Cache المنتج لمدة 10 ثواني (للأداء تحت الضغط)
            $cacheKey = "product:{$id}:details";

            $productData = Cache::remember($cacheKey, now()->addSeconds(10), function () use ($id) {
                $product = Product::findOrFail($id);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $product->price,
                    'stock' => $product->stock,
                    'available_stock' => $product->available_stock, // المخزون المتاح فعلياً
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $productData,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching product',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
