<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminBannerRequest extends FormRequest
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
            'comic_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('comics', 'id')->where(function ($query) {
                    $query->where('approval_status', 'approved');
                }),
            ],
            'title_override' => ['nullable', 'string', 'max:200'],
            'desktop_banner' => [
                'nullable',
                'file',
                'image',
                'mimes:jpeg,png,webp,jpg',
                'max:5120', // 5MB
            ],
            'mobile_banner' => [
                'nullable',
                'file',
                'image',
                'mimes:jpeg,png,webp,jpg',
                'max:5120', // 5MB
            ],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:255'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * Get custom error messages for validation rules.
     */
    public function messages(): array
    {
        return [
            'comic_id.required' => 'Vui lòng chọn bộ truyện tranh liên kết.',
            'comic_id.exists' => 'Bộ truyện được chọn không tồn tại hoặc chưa được duyệt (chỉ cho phép truyện đã approved).',
            'desktop_banner.image' => 'File tải lên phải là định dạng hình ảnh hợp lệ.',
            'desktop_banner.mimes' => 'Ảnh banner desktop phải có định dạng: jpeg, png, webp, jpg.',
            'desktop_banner.max' => 'Dung lượng ảnh banner desktop không được vượt quá 5MB.',
            'mobile_banner.image' => 'File tải lên cho mobile phải là định dạng hình ảnh hợp lệ.',
            'mobile_banner.mimes' => 'Ảnh banner mobile phải có định dạng: jpeg, png, webp, jpg.',
            'mobile_banner.max' => 'Dung lượng ảnh banner mobile không được vượt quá 5MB.',
            'sort_order.min' => 'Thứ tự hiển thị phải là số nguyên không âm.',
            'sort_order.max' => 'Thứ tự hiển thị không được vượt quá 255.',
        ];
    }
}
