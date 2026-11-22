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
                'required',
                'string',
                'max:2000',
            ],
            'type' => [
                'sometimes',
                'string',
                'in:text',
            ],
            'client_message_id' => [
                'nullable',
                'string',
                'uuid',
                'max:36',
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
            'body.required' => 'Nội dung tin nhắn không được để trống.',
            'body.string' => 'Nội dung tin nhắn phải là chuỗi ký tự.',
            'body.max' => 'Nội dung tin nhắn không được vượt quá 2000 ký tự.',
            'type.in' => 'Loại tin nhắn hiện tại chỉ hỗ trợ dạng văn bản (text).',
            'client_message_id.uuid' => 'client_message_id phải là định dạng UUID hợp lệ.',
            'sender_id.prohibited' => 'Không được phép chỉ định sender_id từ client.',
            'conversation_id.prohibited' => 'Không được phép truyền conversation_id trong payload.',
            'created_at.prohibited' => 'Không được phép truyền trường hệ thống created_at.',
            'updated_at.prohibited' => 'Không được phép truyền trường hệ thống updated_at.',
        ];
    }
}
