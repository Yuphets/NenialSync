<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $q = Product::query()->where('is_active', true);
        if ($s = $request->string('search')->trim()->value()) {
            $q->where(fn ($x) => $x->where('name', 'like', "%$s%")->orWhere('barcode', 'like', "%$s%")->orWhere('sku', 'like', "%$s%")->orWhere('supplier', 'like', "%$s%"));
        }if ($c = $request->string('category')->trim()->value()) {
            $q->where('category', $c);
        }

        return $q->orderBy('name')->paginate(100);
    }

    public function store(Request $request, InventoryService $inventory)
    {
        $this->admin($request);
        $data = $this->validated($request);
        $openingStock = (int) $data['stock_quantity'];
        $data['stock_quantity'] = 0;
        $product = DB::transaction(function () use ($data, $inventory, $openingStock, $request) {
            $product = Product::create($data);

            return $openingStock > 0
                ? $inventory->adjust($product, $openingStock, 0, 'opening_stock', $request->user(), 'Opening inventory balance')
                : $product;
        });

        return response()->json($product, 201);
    }

    public function update(Request $request, Product $product)
    {
        $this->admin($request);
        $data = $this->validated($request, $product->id);
        unset($data['stock_quantity']);
        $product->update($data);

        return $product->fresh();
    }

    public function destroy(Request $request, Product $product)
    {
        $this->admin($request);
        abort_if($product->reserved_quantity > 0, 422, 'Products with active reservations cannot be removed.');
        $product->update(['is_active' => false]);
        $product->delete();

        return response()->noContent();
    }

    public function adjust(Request $request, Product $product, InventoryService $inventory)
    {
        $this->admin($request);
        $data = $request->validate(['quantity_delta' => 'required|integer|not_in:0', 'reason' => 'required|string|max:255']);

        return $inventory->adjust($product, $data['quantity_delta'], 0, 'manual_adjustment', $request->user(), $data['reason']);
    }

    public function changes(Request $request)
    {
        $since = $request->date('since') ?: now()->subMinutes(5);

        return response()->json(['server_time' => now()->toIso8601String(), 'products' => Product::where('updated_at', '>', $since)->get()]);
    }

    private function admin(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
    }

    private function validated(Request $r, ?int $id = null)
    {
        return $r->validate(['name' => 'required|string|max:190', 'sku' => 'required|string|max:80|unique:products,sku,'.($id ?? 'NULL'), 'barcode' => 'required|string|max:120|unique:products,barcode,'.($id ?? 'NULL'), 'category' => 'required|string|max:80', 'supplier' => 'nullable|string|max:190', 'unit' => 'required|string|max:32', 'price' => 'required|numeric|min:0.01', 'discount_percent' => 'nullable|numeric|min:0|max:100', 'stock_quantity' => $id ? 'sometimes|integer|min:0' : 'required|integer|min:0', 'safety_stock' => 'nullable|integer|min:0', 'reorder_level' => 'nullable|integer|min:0', 'image_url' => 'nullable|url|max:2048', 'is_active' => 'boolean']);
    }
}
