<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseSelectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // No authentication required for this application
    }

    public function rules(): array
    {
        // Load valid product codes once for this request.
        // A simple pluck on an indexed column — no need to cache it.
        $validCodes = Product::pluck('code')->all();

        return [
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', Rule::in($validCodes)],
            'items.*.quantity'   => ['required', 'integer', 'min:1', 'max:9999'],
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
            'items.*.product_id.in'       => 'The product_id ":input" does not exist. Valid codes are: P001–P005.',
            'items.*.quantity.required'   => 'Each item must include a quantity.',
            'items.*.quantity.integer'    => 'Quantity must be a whole number.',
            'items.*.quantity.min'        => 'Quantity must be at least 1.',
            'items.*.quantity.max'        => 'Quantity cannot exceed 9999 per line.',
        ];
    }

    /**
     * After base validation, detect duplicate product lines.
     * The base rules above handle unknown product codes via Rule::in.
     * Duplicates are flagged separately so the caller receives a clear message.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);

            if (! is_array($items)) {
                return;
            }

            $seen = [];
            foreach ($items as $index => $item) {
                $code = $item['product_id'] ?? null;

                if (! is_string($code)) {
                    continue; // Already caught by base rules
                }

                if (isset($seen[$code])) {
                    $validator->errors()->add(
                        "items.{$index}.product_id",
                        "Duplicate product_id \"{$code}\". Submit each product once and combine quantities into a single line."
                    );
                }

                $seen[$code] = true;
            }
        });
    }
}
