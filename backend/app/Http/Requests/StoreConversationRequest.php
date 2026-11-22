<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
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
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                function ($attribute, $value, $fail) {
                    if ($this->user() && (int) $value === (int) $this->user()->id) {
                        $fail('Không thể tạo cuộc trò chuyện với chính mình.');
                    }
                },
            ],
            'type' => ['prohibited'],
            'user_one_id' => ['prohibited'],
            'user_two_id' => ['prohibited'],
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
            'user_id.required' => 'Vui lòng cung cấp ID người nhận.',
            'user_id.integer' => 'ID người nhận phải là số nguyên.',
            'user_id.exists' => 'Người dùng được chọn không tồn tại trong hệ thống.',
            'type.prohibited' => 'Không được phép truyền trường type từ client.',
            'user_one_id.prohibited' => 'Không được phép truyền trường user_one_id từ client.',
            'user_two_id.prohibited' => 'Không được phép truyền trường user_two_id từ client.',
        ];
    }
}
