<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkConversationReadRequest extends FormRequest
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
            'last_read_message_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'user_id' => ['prohibited'],
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
            'last_read_message_id.required' => 'Vui lòng cung cấp ID tin nhắn đã đọc gần nhất.',
            'last_read_message_id.integer' => 'last_read_message_id phải là số nguyên.',
            'last_read_message_id.min' => 'last_read_message_id phải lớn hơn hoặc bằng 1.',
            'user_id.prohibited' => 'Không được phép truyền user_id từ client.',
        ];
    }
}
