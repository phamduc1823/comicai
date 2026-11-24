<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReorderAdminBannersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'orders' => ['required', 'array', 'min:1'],
            'orders.*.id' => ['required', 'integer', 'exists:banners,id'],
            'orders.*.sort_order' => ['required', 'integer', 'min:0', 'max:255'],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'orders.required' => 'Vui lòng cung cấp danh sách thứ tự banner cần cập nhật.',
            'orders.array' => 'Dữ liệu thứ tự phải là một mảng danh sách.',
            'orders.*.id.exists' => 'Một hoặc nhiều banner trong danh sách không tồn tại.',
            'orders.*.sort_order.min' => 'Thứ tự hiển thị phải là số nguyên không âm.',
        ];
    }
}
