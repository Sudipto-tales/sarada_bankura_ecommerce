<?php

namespace App\Requests;

use Vayu\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:255',
            'sku' => 'required|string|max:64|unique:products,sku',
            'status' => 'required|in:draft,active,coming_soon,archived',
            'product_type_id' => 'required',
            'short_description' => 'nullable|max:500',
            'price' => 'nullable|numeric',
            'compare_at_price' => 'nullable|numeric',
            'cost_price' => 'nullable|numeric',
            'meta_description' => 'nullable|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Product name is required.',
            'sku.required' => 'SKU is required.',
            'sku.unique' => 'This SKU is already in use.',
        ];
    }
}
