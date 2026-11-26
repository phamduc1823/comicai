<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GrantReapplyRequest extends FormRequest
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
            'reason.required' => 'Lý do cấp phép đăng ký lại là bắt buộc.',
            'reason.min' => 'Lý do cấp phép phải có tối thiểu 3 ký tự.',
            'reason.max' => 'Lý do cấp phép không được vượt quá 1000 ký tự.',
        ];
    }
}
