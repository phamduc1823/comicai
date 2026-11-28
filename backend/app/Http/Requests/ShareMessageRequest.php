<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShareMessageRequest extends FormRequest
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
        $authId = $this->user()?->id;

        return [
            'recipient_ids' => [
                'required',
                'array',
                'min:1',
                'max:20',
            ],
            'recipient_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:users,id',
                function ($attribute, $value, $fail) use ($authId) {
                    if ($authId && (int) $value === (int) $authId) {
                        $fail('Không thể chia sẻ nội dung cho chính mình.');
                    }
                },
            ],
            'comic_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:comics,id',
                'required_without:post_id',
            ],
            'post_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:posts,id',
                'required_without:comic_id',
            ],
            'body' => [
                'nullable',
                'string',
                'max:1000',
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
            'recipient_ids.required' => 'Vui lòng chọn ít nhất một người nhận.',
            'recipient_ids.min' => 'Vui lòng chọn ít nhất một người nhận.',
            'recipient_ids.max' => 'Chỉ có thể chia sẻ tối đa cho 20 người cùng lúc.',
            'recipient_ids.*.exists' => 'Người nhận không tồn tại trong hệ thống.',
            'comic_id.required_without' => 'Vui lòng chọn truyện hoặc bài viết cần chia sẻ.',
            'post_id.required_without' => 'Vui lòng chọn truyện hoặc bài viết cần chia sẻ.',
            'comic_id.exists' => 'Bộ truyện được chia sẻ không tồn tại.',
            'post_id.exists' => 'Bài viết được chia sẻ không tồn tại.',
            'body.max' => 'Lời nhắn chia sẻ không được vượt quá 1000 ký tự.',
        ];
    }
}
