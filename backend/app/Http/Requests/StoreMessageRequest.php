<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => [
                'nullable',
                'string',
                'max:2000',
                'required_without_all:images,comic_id,post_id',
            ],
            'type' => [
                'sometimes',
                'string',
                'in:text,image,comic,post,mixed',
            ],
            'client_message_id' => [
                'nullable',
                'string',
                'uuid',
                'max:36',
            ],
            'comic_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:comics,id',
            ],
            'post_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:posts,id',
            ],
            'images' => [
                'sometimes',
                'array',
                'max:5',
            ],
            'images.*' => [
                'file',
                'mimes:jpeg,jpg,png,webp',
                'max:5120',
            ],
            'sender_id' => ['prohibited'],
            'conversation_id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
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
            'body.required_without_all' => 'Nội dung tin nhắn không được để trống khi không có hình ảnh hoặc truyện đính kèm.',
            'body.string' => 'Nội dung tin nhắn phải là chuỗi ký tự.',
            'body.max' => 'Nội dung tin nhắn không được vượt quá 2000 ký tự.',
            'type.in' => 'Loại tin nhắn không hợp lệ.',
            'client_message_id.uuid' => 'client_message_id phải là định dạng UUID hợp lệ.',
            'comic_id.exists' => 'Bộ truyện được đính kèm không tồn tại trong hệ thống.',
            'post_id.exists' => 'Bài viết được đính kèm không tồn tại trong hệ thống.',
            'images.max' => 'Tối đa chỉ được đính kèm 5 hình ảnh trong mỗi tin nhắn.',
            'images.*.file' => 'Tập tin tải lên phải là file hợp lệ.',
            'images.*.mimes' => 'Hình ảnh đính kèm phải có định dạng JPEG, PNG hoặc WebP.',
            'images.*.max' => 'Mỗi hình ảnh đính kèm không được vượt quá 5MB.',
        ];
    }
}
