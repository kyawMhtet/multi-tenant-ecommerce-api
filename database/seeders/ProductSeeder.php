<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Tenant;
use App\Services\ProductService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    /**
     * Attaches sample catalog data to the tenant TenantSeeder already
     * created — looked up by its known slug rather than created here,
     * since this seeder must never create its own tenant (that would
     * defeat the point of having one predictable test tenant to log into).
     *
     * Goes through ProductService/Category::create() rather than raw
     * inserts, on purpose: that's the only way to get a correctly
     * generated variant slug, a real stock_movements ledger row for
     * starting stock, and tenant_id auto-filled the same way a real
     * request would produce it — seeded data that took a shortcut around
     * the app's own invariants wouldn't be a realistic stand-in for it.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'test-shop')->firstOrFail();

        app()->instance('tenant', $tenant);

        $productService = app(ProductService::class);

        $categories = collect([
            'Drinks', 'Snacks', 'Clothing', 'Household Goods', 'Personal Care',
        ])->mapWithKeys(fn (string $name) => [
            $name => Category::create(['name' => $name, 'slug' => Str::slug($name)])->id,
        ]);

        $products = $this->products($categories);

        foreach ($products as $product) {
            $created = $productService->createProduct([
                'name' => $product['name'],
                'description' => $product['description'],
                'category_id' => $product['category_id'],
                // No demo image files to seed — product_images now
                // requires a real, already-optimized stored file
                // (ImageUploadService), and there's nothing to fake that
                // wouldn't imply a working image that doesn't exist.
                // Seeded products simply have zero images.
                'is_active' => true,
                'variant' => $product['variants'][0],
            ]);

            foreach (array_slice($product['variants'], 1) as $variantData) {
                $productService->addVariant($created, $variantData);
            }
        }

        $this->command->info('Seeded '.count($products).' products for tenant "test-shop".');

        app()->forgetInstance('tenant');
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $categories
     * @return array<int, array{name: string, description: string, category_id: int, variants: array<int, array<string, mixed>>}>
     */
    private function products(\Illuminate\Support\Collection $categories): array
    {
        return [
            // Simple products (one variant each), a healthy mix of stock
            // levels including the low-stock and out-of-stock edge cases
            // the task specifically asked for.
            [
                'name' => 'Milo Chocolate Malt Drink 400g',
                'description' => 'Chocolate malt drink powder, 400g tin.',
                'category_id' => $categories['Drinks'],
                'variants' => [[
                    'sku' => 'MILO-400', 'unit' => 'tin',
                    'buying_price' => 4500, 'selling_price' => 5800,
                    'current_stock' => 30, 'low_stock_threshold' => 8,
                ]],
            ],
            [
                'name' => 'Premier Coffee Mix 3-in-1 (Box of 30)',
                'description' => 'Instant coffee mix sachets, box of 30.',
                'category_id' => $categories['Drinks'],
                'variants' => [[
                    // At (not below) its threshold — the low-stock edge case.
                    'sku' => 'PREMIER-CMX30', 'unit' => 'box',
                    'buying_price' => 3200, 'selling_price' => 4200,
                    'current_stock' => 3, 'low_stock_threshold' => 5,
                ]],
            ],
            [
                'name' => 'Alpine Full Cream Milk Powder 400g',
                'description' => 'Full cream milk powder, 400g tin.',
                'category_id' => $categories['Drinks'],
                'variants' => [[
                    // Out-of-stock edge case.
                    'sku' => 'ALPINE-FCM400', 'unit' => 'tin',
                    'buying_price' => 5200, 'selling_price' => 6800,
                    'current_stock' => 0, 'low_stock_threshold' => 5,
                ]],
            ],
            [
                'name' => "Lay's Potato Chips Classic 60g",
                'description' => 'Classic salted potato chips, 60g pack.',
                'category_id' => $categories['Snacks'],
                'variants' => [[
                    'sku' => 'LAYS-CLS60', 'unit' => 'pack',
                    'buying_price' => 900, 'selling_price' => 1500,
                    'current_stock' => 50, 'low_stock_threshold' => 10,
                ]],
            ],
            [
                'name' => 'Mama Instant Noodles Chicken (Pack of 5)',
                'description' => 'Chicken-flavor instant noodles, pack of 5.',
                'category_id' => $categories['Snacks'],
                'variants' => [[
                    'sku' => 'MAMA-CHK5', 'unit' => 'pack',
                    'buying_price' => 2200, 'selling_price' => 3000,
                    'current_stock' => 40, 'low_stock_threshold' => 10,
                ]],
            ],
            [
                'name' => 'Ah One Fried Peanuts 100g',
                'description' => 'Crispy fried peanuts, 100g pack.',
                'category_id' => $categories['Snacks'],
                'variants' => [[
                    'sku' => 'AHONE-PNT100', 'unit' => 'pack',
                    'buying_price' => 700, 'selling_price' => 1200,
                    'current_stock' => 45, 'low_stock_threshold' => 10,
                ]],
            ],
            [
                'name' => 'Kyone Yeom Dishwashing Liquid 750ml',
                'description' => 'Dishwashing liquid, 750ml bottle.',
                'category_id' => $categories['Household Goods'],
                'variants' => [[
                    'sku' => 'KYONEYEOM-DW750', 'unit' => 'bottle',
                    'buying_price' => 1800, 'selling_price' => 2800,
                    'current_stock' => 20, 'low_stock_threshold' => 5,
                ]],
            ],
            [
                'name' => 'Duracell AA Batteries (Pack of 4)',
                'description' => 'Alkaline AA batteries, pack of 4.',
                'category_id' => $categories['Household Goods'],
                'variants' => [[
                    'sku' => 'DURACELL-AA4', 'unit' => 'pack',
                    'buying_price' => 2500, 'selling_price' => 3800,
                    'current_stock' => 25, 'low_stock_threshold' => 8,
                ]],
            ],
            [
                'name' => 'Mosquito Coil (Box of 10)',
                'description' => 'Mosquito repellent coils, box of 10.',
                'category_id' => $categories['Household Goods'],
                'variants' => [[
                    'sku' => 'MOSQCOIL-10', 'unit' => 'box',
                    'buying_price' => 1200, 'selling_price' => 2000,
                    'current_stock' => 35, 'low_stock_threshold' => 10,
                ]],
            ],
            [
                'name' => 'Colgate Toothpaste 150g',
                'description' => 'Fluoride toothpaste, 150g tube.',
                'category_id' => $categories['Personal Care'],
                'variants' => [[
                    'sku' => 'COLGATE-150', 'unit' => 'tube',
                    'buying_price' => 1600, 'selling_price' => 2500,
                    'current_stock' => 30, 'low_stock_threshold' => 8,
                ]],
            ],
            [
                'name' => 'Dettol Antiseptic Soap Bar 100g',
                'description' => 'Antiseptic soap bar, 100g.',
                'category_id' => $categories['Personal Care'],
                'variants' => [[
                    'sku' => 'DETTOL-SOAP100', 'unit' => 'bar',
                    'buying_price' => 700, 'selling_price' => 1200,
                    'current_stock' => 40, 'low_stock_threshold' => 10,
                ]],
            ],
            [
                'name' => 'Sunsilk Shampoo Sachet (Pack of 12)',
                'description' => 'Shampoo sachets, pack of 12.',
                'category_id' => $categories['Personal Care'],
                'variants' => [[
                    'sku' => 'SUNSILK-SCH12', 'unit' => 'pack',
                    'buying_price' => 1500, 'selling_price' => 2400,
                    'current_stock' => 18, 'low_stock_threshold' => 6,
                ]],
            ],

            // Real multi-variant products: size/color combinations sharing
            // one product listing, each with its own price and stock.
            [
                'name' => "Men's Plain Cotton T-Shirt",
                'description' => 'Plain cotton crew-neck t-shirt.',
                'category_id' => $categories['Clothing'],
                'variants' => [
                    [
                        'sku' => 'TSHIRT-WHT-S', 'variant_name' => 'White / S',
                        'attributes' => ['color' => 'White', 'size' => 'S'],
                        'buying_price' => 3500, 'selling_price' => 6000,
                        'current_stock' => 15, 'low_stock_threshold' => 5,
                    ],
                    [
                        'sku' => 'TSHIRT-WHT-M', 'variant_name' => 'White / M',
                        'attributes' => ['color' => 'White', 'size' => 'M'],
                        'buying_price' => 3500, 'selling_price' => 6000,
                        'current_stock' => 12, 'low_stock_threshold' => 5,
                    ],
                    [
                        'sku' => 'TSHIRT-BLK-L', 'variant_name' => 'Black / L',
                        'attributes' => ['color' => 'Black', 'size' => 'L'],
                        'buying_price' => 3500, 'selling_price' => 6000,
                        'current_stock' => 10, 'low_stock_threshold' => 5,
                    ],
                ],
            ],
            [
                'name' => "Women's Casual Blouse",
                'description' => 'Casual short-sleeve blouse.',
                'category_id' => $categories['Clothing'],
                'variants' => [
                    [
                        'sku' => 'BLOUSE-S', 'variant_name' => 'S',
                        'attributes' => ['size' => 'S'],
                        'buying_price' => 4000, 'selling_price' => 7500,
                        'current_stock' => 8, 'low_stock_threshold' => 5,
                    ],
                    [
                        'sku' => 'BLOUSE-M', 'variant_name' => 'M',
                        'attributes' => ['size' => 'M'],
                        'buying_price' => 4000, 'selling_price' => 7500,
                        'current_stock' => 6, 'low_stock_threshold' => 5,
                    ],
                ],
            ],
        ];
    }
}
