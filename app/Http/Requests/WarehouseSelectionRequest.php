<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;

class WarehouseSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // No authentication in this challenge
    }

    public function rules(): array
    {
        return [
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.product_id'     => ['required', 'string'],
            'items.*.quantity'       => ['required', 'integer', 'min:1', 'max:9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required'              => 'At least one order item is required.',
            'items.array'                 => 'Order items must be provided as an array.',
            'items.min'                   => 'At least one order item is required.',
            'items.*.product_id.required' => 'Each item must include a product_id.',
            'items.*.product_id.string'   => 'product_id must be a string (e.g. "P001").',
            'items.*.quantity.required'   => 'Each item must include a quantity.',
            'items.*.quantity.integer'    => 'Quantity must be a whole number.',
            'items.*.quantity.min'        => 'Quantity must be at least 1.',
            'items.*.quantity.max'        => 'Quantity cannot exceed 9999 per line.',
        ];
    }

    /**
     * After base validation, verify every product_id exists and detect
     * duplicates.  Unknown product codes are flagged as validation errors.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);

            if (! is_array($items)) {
                return;
            }

            // Load all valid product codes once
            $validCodes = Cache::remember('product_codes', 60, fn () => Product::pluck('code')->all());

            $seen = [];
            foreach ($items as $index => $item) {
                $code = $item['product_id'] ?? null;

                if (! is_string($code)) {
                    continue; // Already caught by base rules
                }

                if (! in_array($code, $validCodes, true)) {
                    $validator->errors()->add(
                        "items.{$index}.product_id",
                        "Product ID \"{$code}\" does not exist."
                    );
                }

                if (isset($seen[$code])) {
                    // Duplicate — flag it; the service will normalize, but we
                    // surface the duplicate so the caller is aware.
                    $validator->errors()->add(
                        "items.{$index}.product_id",
                        "Duplicate product ID \"{$code}\". Combine quantities into a single line."
                    );
                }
                $seen[$code] = true;
            }
        });
    }
}
