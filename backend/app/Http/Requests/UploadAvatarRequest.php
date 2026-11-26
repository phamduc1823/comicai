<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadAvatarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,webp,jpg',
                'max:2048', // Max 2MB (2048 KB)
            ],
        ];
    }

    /**
     * Custom Vietnamese error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Vui lòng chọn tệp ảnh đại diện.',
            'avatar.file' => 'Tệp tải lên không hợp lệ.',
            'avatar.image' => 'Tệp tải lên phải là hình ảnh hợp lệ.',
            'avatar.mimes' => 'Ảnh đại diện chỉ chấp nhận các định dạng JPEG, PNG, WEBP.',
            'avatar.max' => 'Dung lượng ảnh đại diện không được vượt quá 2MB.',
        ];
    }
}
