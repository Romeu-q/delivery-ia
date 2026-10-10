<?php

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;

/**
 * Monta cenários pequenos e exatos, para que os testes saibam o resultado esperado de cada consulta.
 */
trait CreatesDeliveryData
{
    protected function store(string $name = 'Loja Teste'): Store
    {
        return Store::query()->create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid()]);
    }

    protected function product(Store $store, string $name, string $category, float $price): Product
    {
        return Product::query()->create(compact('name', 'category', 'price') + ['store_id' => $store->id]);
    }

    protected function customer(Store $store, string $name = 'Cliente'): Customer
    {
        return Customer::query()->create(['store_id' => $store->id, 'name' => $name, 'phone' => '(11) 98765-4321']);
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $items  [produto, quantidade]
     */
    protected function order(Customer $customer, string $at, array $items, float $discount = 0, float $fee = 0, string $status = 'delivered'): Order
    {
        $subtotal = array_sum(array_map(fn ($i) => $i[0]->price * $i[1], $items));

        $order = Order::query()->create([
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'ordered_at' => $at,
            'status' => $status,
            'payment_method' => 'pix',
            'subtotal' => $subtotal,
            'discount' => $discount,
            'delivery_fee' => $fee,
            'total' => $subtotal - $discount + $fee,
        ]);

        foreach ($items as [$product, $quantity]) {
            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => $product->price,
                'total' => $product->price * $quantity,
            ]);
        }

        $first = $customer->first_order_at && $customer->first_order_at->lt($at) ? $customer->first_order_at : $at;
        $last = $customer->last_order_at && $customer->last_order_at->gt($at) ? $customer->last_order_at : $at;
        $customer->update(['first_order_at' => $first, 'last_order_at' => $last]);

        return $order;
    }
}
