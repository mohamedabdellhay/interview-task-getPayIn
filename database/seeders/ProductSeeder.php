<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        Product::create([
            'name' => 'Limited Edition iPhone 16 Pro',
            'price' => 1299.99,
            'stock' => 100, // limited stock for flash sale
        ]);
    }
}
