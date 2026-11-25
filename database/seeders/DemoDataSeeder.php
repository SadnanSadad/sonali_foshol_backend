<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Models\CalendarEvent;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderSequence;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    private const IMAGE_DIR = 'products';

    public function run(): void
    {
        $admin = $this->loadJson('admin');
        $storefront = $this->loadJson('storefront/data');

        DB::transaction(function () use ($admin, $storefront) {
            $superadmin = $this->seedSuperadmin($admin['admin']);
            $this->seedSettings($admin['settings'], $storefront['storeSettings']);
            $categories = $this->seedCategories($admin['categories'], $storefront['categories']);
            $products = $this->seedProducts($admin['products'], $storefront['products'], $categories);
            $customers = $this->seedCustomers($admin['customers']);
            $this->seedOrders($admin['orders'], $products, $customers, $superadmin);
            $this->seedReviews($admin['reviews'], $storefront['reviews'], $products);
            $this->seedCalendar($admin['calendar-events']);
            $this->seedOrderSequence();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $name): array
    {
        $base = database_path('seeders/data/'.($name === 'admin' ? 'admin' : ''));

        if ($name === 'admin') {
            return [
                'admin' => $this->readJson($base.'/admin.json'),
                'settings' => $this->readJson($base.'/settings.json'),
                'categories' => $this->readJson($base.'/categories.json'),
                'products' => $this->readJson($base.'/products.json'),
                'customers' => $this->readJson($base.'/customers.json'),
                'orders' => $this->readJson($base.'/orders.json'),
                'reviews' => $this->readJson($base.'/reviews.json'),
                'calendar-events' => $this->readJson($base.'/calendar-events.json'),
            ];
        }

        return $this->readJson(database_path('seeders/data/'.$name.'.json'));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function readJson(string $path): array
    {
        if (! File::exists($path)) {
            throw new \RuntimeException("Seed data file not found: {$path}");
        }

        return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedSuperadmin(array $admin): User
    {
        $email = strtolower((string) env('SUPERADMIN_EMAIL', $admin['email']));
        $user = User::query()->where('email', $email)->first();

        if ($user) {
            return $user;
        }

        return User::query()->create([
            'name' => (string) env('SUPERADMIN_NAME', $admin['name']),
            'email' => $email,
            'username' => strtolower((string) env('SUPERADMIN_USERNAME', $admin['username'])),
            'password' => Hash::make((string) env('SUPERADMIN_PASSWORD', $admin['password'])),
            'role' => AdminRole::Superadmin,
            'avatar' => 'storage/avatar-admin.svg',
            'is_active' => true,
        ]);
    }

    private function seedSettings(array $settings, array $storefront): void
    {
        Setting::query()->updateOrCreate(['id' => 1], [
            'store_name' => $settings['storeName'],
            'store_name_bn' => $settings['storeNameBn'],
            'brand_tagline_bn' => $storefront['brandTaglineBn'],
            'whatsapp_number' => $settings['whatsappNumber'],
            'facebook_url' => $settings['facebookUrl'],
            'messenger_url' => $settings['messengerUrl'],
            'delivery_fee_inside' => (int) $settings['deliveryFeeInside'],
            'delivery_fee_outside' => (int) $settings['deliveryFeeOutside'],
            'free_shipping_threshold' => (int) $settings['freeShippingThreshold'],
            'low_stock_threshold' => (int) $settings['lowStockThreshold'],
            'phone_display' => $storefront['phoneDisplay'],
            'email' => $storefront['email'],
            'address_bn' => $storefront['addressBn'],
            'inside_delivery_days' => 2,
            'outside_delivery_days' => 4,
        ]);
    }

    /**
     * @return array<string, Category>
     */
    private function seedCategories(array $adminCategories, array $storefrontCategories): array
    {
        $categories = [];

        foreach ($adminCategories as $row) {
            $categories[$row['slug']] = Category::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name_en' => $row['nameEn'],
                    'name_bn' => $row['nameBn'],
                    'sort_order' => (int) $row['order'],
                    'hidden' => (bool) $row['hidden'],
                ],
            );
        }

        foreach ($storefrontCategories as $row) {
            if ($row['slug'] === 'all' || isset($categories[$row['slug']])) {
                continue;
            }

            $categories[$row['slug']] = Category::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name_en' => ucfirst(str_replace('-', ' ', $row['slug'])),
                    'name_bn' => $row['nameBn'],
                    'sort_order' => count($categories) + 1,
                    'hidden' => false,
                ],
            );
        }

        return $categories;
    }

    /**
     * @return array<string, Product>
     */
    private function seedProducts(array $adminProducts, array $storefrontProducts, array $categories): array
    {
        $products = [];

        foreach ($adminProducts as $row) {
            $product = Product::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'category_id' => $categories[$row['category']]->id,
                    'name_bn' => $row['nameBn'],
                    'name_en' => $row['nameEn'] ?? null,
                    'origin_bn' => $row['originBn'] ?? null,
                    'short_desc_bn' => $row['shortDescBn'],
                    'full_desc_bn' => $row['fullDescBn'],
                    'image_path' => $this->imagePath($row['image']),
                    'badge_bn' => $row['badgeBn'] ?? null,
                    'in_stock' => (bool) ($row['inStock'] ?? true),
                    'status' => $row['status'] ?? 'active',
                    'nutrition_highlights' => $row['nutritionHighlights'] ?? [],
                    'nutrition_facts' => $row['nutritionFacts'] ?? [],
                    'cooking_tips_bn' => $row['cookingTipsBn'] ?? null,
                ],
            );

            if (isset($row['createdAt'])) {
                $product->forceFill([
                    'created_at' => Carbon::parse($row['createdAt']),
                    'updated_at' => Carbon::parse($row['updatedAt'] ?? $row['createdAt']),
                ])->save();
            }

            $this->syncImages($product, $row['images'] ?? [$row['image']]);
            $this->syncWeights($product, $row['weights']);

            $products[$product->slug] = $product->load('weights');
        }

        foreach ($storefrontProducts as $row) {
            $product = Product::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'category_id' => $categories[$row['category']]->id,
                    'name_bn' => $row['nameBn'],
                    'name_en' => $row['nameEn'] ?? null,
                    'origin_bn' => $row['originBn'] ?? null,
                    'short_desc_bn' => $row['shortDescBn'],
                    'full_desc_bn' => $row['fullDescBn'],
                    'image_path' => $this->imagePath($row['image'], 'sf-'),
                    'badge_bn' => $row['badgeBn'] ?? null,
                    'in_stock' => (bool) ($row['inStock'] ?? true),
                    'status' => 'active',
                    'nutrition_highlights' => $row['nutritionHighlights'] ?? [],
                    'nutrition_facts' => $row['nutritionFacts'] ?? [],
                    'cooking_tips_bn' => $row['cookingTipsBn'] ?? null,
                ],
            );

            $images = array_map(fn ($image) => $this->imagePath($image, 'sf-'), $row['images'] ?? [$row['image']]);
            $this->syncImages($product, $images);

            $weights = [];
            foreach ($row['weights'] as $index => $weight) {
                $weights[] = [
                    'label' => $weight['label'],
                    'grams' => (int) $weight['grams'],
                    'price' => (int) $weight['price'],
                    'mrp' => (int) $weight['mrp'],
                    'stock' => [40, 25, 12][$index] ?? 10,
                    'sku' => 'SF-'.strtoupper($row['slug']).'-'.$weight['grams'],
                ];
            }
            $this->syncWeights($product, $weights);

            $products[$product->slug] = $product->load('weights');
        }

        return $products;
    }

    private function imagePath(string $path, string $prefix = ''): string
    {
        return self::IMAGE_DIR.'/'.$prefix.basename($path);
    }

    private function syncImages(Product $product, array $images): void
    {
        $product->images()->delete();

        foreach (array_values(array_unique($images)) as $index => $path) {
            $product->images()->create([
                'path' => str_starts_with($path, 'products/') ? $path : $this->imagePath($path),
                'sort_order' => $index,
            ]);
        }
    }

    private function syncWeights(Product $product, array $weights): void
    {
        foreach (array_values($weights) as $index => $weight) {
            $product->weights()->updateOrCreate(
                ['grams' => (int) $weight['grams']],
                [
                    'label' => $weight['label'],
                    'price' => (int) $weight['price'],
                    'mrp' => (int) $weight['mrp'],
                    'stock' => (int) $weight['stock'],
                    'sku' => $weight['sku'] ?? null,
                    'sort_order' => $index,
                ],
            );
        }
    }

    /**
     * @return array<string, Customer>
     */
    private function seedCustomers(array $rows): array
    {
        $customers = [];

        foreach ($rows as $row) {
            $customer = Customer::query()->updateOrCreate(
                ['phone' => $row['phone']],
                [
                    'name' => $row['name'],
                    'district' => $row['district'],
                    'address' => $row['address'],
                    'flagged' => (bool) ($row['flagged'] ?? false),
                    'last_order_at' => Carbon::parse($row['lastOrderAt']),
                ],
            );

            $customer->forceFill([
                'created_at' => Carbon::parse($row['createdAt']),
            ])->save();

            $customers[$customer->phone] = $customer;
        }

        return $customers;
    }

    private function seedOrders(array $rows, array $products, array $customers, User $admin): void
    {
        foreach ($rows as $row) {
            $customer = $customers[$row['customer']['phone']] ?? null;

            $order = Order::query()->updateOrCreate(
                ['order_number' => $row['orderNumber']],
                [
                    'customer_id' => $customer?->id,
                    'status' => $row['status'],
                    'payment_method' => $row['paymentMethod'],
                    'delivery_zone' => $row['deliveryZone'],
                    'customer_name' => $row['customer']['name'],
                    'customer_phone' => $row['customer']['phone'],
                    'customer_email' => null,
                    'district' => $row['customer']['district'],
                    'address' => $row['customer']['address'],
                    'note' => $row['customer']['note'] ?? null,
                    'subtotal' => (int) $row['subtotal'],
                    'delivery_fee' => (int) $row['deliveryFee'],
                    'discount' => (int) ($row['discount'] ?? 0),
                    'total' => (int) $row['total'],
                    'expected_delivery_date' => isset($row['expectedDeliveryDate'])
                        ? Carbon::parse($row['expectedDeliveryDate'])
                        : null,
                    'placed_by' => 'customer',
                    'rejection_reason' => $row['rejection']['reason'] ?? null,
                    'rejection_feedback' => $row['rejection']['feedback'] ?? null,
                    'rejected_by' => $row['rejection']['by'] ?? null,
                    'rejected_at' => isset($row['rejection']['at']) ? Carbon::parse($row['rejection']['at']) : null,
                    'courier_name' => $row['courier']['name'] ?? null,
                    'courier_tracking_id' => $row['courier']['trackingId'] ?? null,
                    'stock_restored_at' => in_array($row['status'], ['rejected', 'cancelled'], true)
                        ? Carbon::parse($row['statusHistory'][array_key_last($row['statusHistory'])]['at'])
                        : null,
                    'created_at' => Carbon::parse($row['createdAt']),
                    'updated_at' => Carbon::parse($row['statusHistory'][array_key_last($row['statusHistory'])]['at']),
                ],
            );

            $order->items()->delete();

            foreach ($row['items'] as $item) {
                $product = $products[$item['slug']] ?? null;
                $weight = $product?->weights->firstWhere('label', $item['weightLabel']);

                if (! $product || ! $weight) {
                    throw new \RuntimeException("Unable to map order item {$item['slug']} / {$item['weightLabel']}.");
                }

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_weight_id' => $weight->id,
                    'product_slug' => $product->slug,
                    'product_name_bn' => $item['title'],
                    'weight_label' => $item['weightLabel'],
                    'grams' => $weight->grams,
                    'unit_price' => (int) $item['unitPrice'],
                    'qty' => (int) $item['qty'],
                    'line_total' => (int) $item['unitPrice'] * (int) $item['qty'],
                    'image_path' => $this->imagePath($item['image']),
                ]);
            }

            $order->statusHistory()->delete();

            foreach ($row['statusHistory'] as $entry) {
                $order->statusHistory()->create([
                    'status' => $entry['status'],
                    'note' => $entry['note'] ?? null,
                    'changed_by_user_id' => ($entry['by'] ?? null) === 'System' ? null : $admin->id,
                    'changed_by_name' => $entry['by'] ?? 'System',
                    'created_at' => Carbon::parse($entry['at']),
                ]);
            }
        }
    }

    private function seedReviews(array $adminRows, array $storefrontRows, array $products): void
    {
        foreach ($adminRows as $row) {
            $product = null;
            if (! empty($row['productId'])) {
                $slug = $this->productSlugFromLegacyId($row['productId']);
                $product = $products[$slug] ?? null;
            }

            Review::query()->updateOrCreate(
                [
                    'author' => $row['author'],
                    'text' => $row['text'],
                    'rating' => (int) $row['rating'],
                ],
                [
                    'product_id' => $product?->id,
                    'product_name_bn' => $row['productNameBn'] ?? $product?->name_bn,
                    'location' => $row['location'] ?? null,
                    'status' => $row['status'] ?? 'approved',
                    'created_at' => Carbon::parse($row['createdAt']),
                ],
            );
        }

        $offset = 1;
        foreach ($storefrontRows as $row) {
            Review::query()->updateOrCreate(
                [
                    'author' => $row['customerName'],
                    'text' => $row['commentBn'],
                    'rating' => (int) $row['rating'],
                ],
                [
                    'product_id' => null,
                    'product_name_bn' => null,
                    'location' => $row['location'] ?? null,
                    'status' => 'approved',
                    'created_at' => now()->subDays($offset++),
                ],
            );
        }
    }

    private function seedCalendar(array $rows): void
    {
        foreach ($rows as $row) {
            CalendarEvent::query()->updateOrCreate(
                [
                    'title' => $row['title'],
                    'start_at' => Carbon::parse($row['start']),
                ],
                [
                    'type' => $row['type'] ?? 'admin',
                    'end_at' => isset($row['end']) ? Carbon::parse($row['end']) : null,
                    'all_day' => (bool) ($row['allDay'] ?? false),
                    'note' => $row['note'] ?? null,
                    'order_id' => null,
                ],
            );
        }
    }

    private function seedOrderSequence(): void
    {
        $maxByYear = [];

        Order::query()->pluck('order_number')->each(function (string $number) use (&$maxByYear) {
            if (preg_match('/^SFO-(\d{4})-(\d+)$/', $number, $matches)) {
                $year = (int) $matches[1];
                $maxByYear[$year] = max($maxByYear[$year] ?? 0, (int) $matches[2]);
            }
        });

        if ($maxByYear === []) {
            return;
        }

        foreach ($maxByYear as $year => $max) {
            OrderSequence::query()->updateOrCreate(
                ['year' => $year],
                ['next_number' => $max + 1],
            );
        }

        $currentYear = (int) now('Asia/Dhaka')->format('Y');
        if (! isset($maxByYear[$currentYear])) {
            OrderSequence::query()->firstOrCreate(['year' => $currentYear], ['next_number' => 1]);
        }
    }

    private function productSlugFromLegacyId(string $id): ?string
    {
        static $map = null;

        if ($map === null) {
            $map = [];
            foreach ($this->readJson(database_path('seeders/data/admin/products.json')) as $row) {
                $map[$row['id']] = $row['slug'];
            }
        }

        return $map[$id] ?? null;
    }
}
