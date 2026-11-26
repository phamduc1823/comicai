<?php

namespace App\Http\Requests;

use App\Helpers\PenNameHelper;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCreatorProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && in_array($this->user()->role, ['creator', 'admin'], true)
            && (bool) $this->user()->is_active;
    }

    public function rules(): array
    {
        return [
            'pen_name' => [
                'nullable',
                'string',
                'min:3',
                'max:50',
                function ($attribute, $value, $fail) {
                    if ($value && ! PenNameHelper::isValid((string) $value)) {
                        $fail('Bút danh không hợp lệ. Phải từ 3 đến 50 ký tự, chỉ gồm chữ cái Unicode, số, khoảng trắng và các ký tự . _ -');
                    }
                },
            ],
            'bio' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'pen_name.min' => 'Bút danh phải có tối thiểu 3 ký tự.',
            'pen_name.max' => 'Bút danh không được vượt quá 50 ký tự.',
            'bio.max' => 'Giới thiệu bản thân không được vượt quá 1000 ký tự.',
        ];
    }
}
