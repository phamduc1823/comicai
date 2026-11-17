<?php

namespace App\Http\Requests;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Post::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => [
                'required',
                'string',
                'max:5000',
                function ($attribute, $value, $fail) {
                    if (trim($value) === '') {
                        $fail('Nội dung bài đăng không được để trống hoặc chỉ chứa khoảng trắng.');
                    }
                },
            ],
            'comic_id' => [
                'nullable',
                'integer',
                Rule::exists('comics', 'id')->where(function ($query) {
                    $query->where('approval_status', 'approved');
                }),
            ],
            'image' => [
                'nullable',
                'file',
                'image',
                'mimes:jpeg,png,jpg,webp',
                'max:2048',
            ],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'Nội dung bài đăng không được để trống.',
            'content.max' => 'Nội dung bài đăng không được vượt quá 5000 ký tự.',
            'comic_id.exists' => 'Truyện được gắn thẻ không tồn tại hoặc chưa được phát hành công khai.',
            'image.image' => 'Tệp tải lên phải là một hình ảnh hợp lệ.',
            'image.mimes' => 'Ảnh bài đăng chỉ chấp nhận định dạng JPEG, PNG, JPG hoặc WebP.',
            'image.max' => 'Kích thước ảnh không được vượt quá 2 MB.',
        ];
    }
}
