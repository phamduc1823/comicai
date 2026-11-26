<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectCreatorApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->role === 'admin' && (bool) $this->user()->is_active;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Lý do từ chối hồ sơ là bắt buộc.',
            'reason.min' => 'Lý do từ chối phải có tối thiểu 3 ký tự.',
            'reason.max' => 'Lý do từ chối không được vượt quá 1000 ký tự.',
        ];
    }
}
