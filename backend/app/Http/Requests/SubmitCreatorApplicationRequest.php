<?php

namespace App\Http\Requests;

use App\Helpers\PenNameHelper;
use Illuminate\Foundation\Http\FormRequest;

class SubmitCreatorApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && (bool) $this->user()->is_active;
    }

    public function rules(): array
    {
        return [
            'pen_name' => [
                'required',
                'string',
                'min:3',
                'max:50',
                function ($attribute, $value, $fail) {
                    if (! PenNameHelper::isValid((string) $value)) {
                        $fail('Bút danh không hợp lệ. Phải từ 3 đến 50 ký tự, chỉ gồm chữ cái Unicode, số, khoảng trắng và các ký tự . _ -');
                    }
                },
            ],
            'bio' => ['required', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:1000'],
            'sample_works' => ['nullable', 'array', 'max:3'],
            'sample_works.*' => ['required', 'url', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'pen_name.required' => 'Bút danh là bắt buộc.',
            'pen_name.min' => 'Bút danh phải có tối thiểu 3 ký tự.',
            'pen_name.max' => 'Bút danh không được vượt quá 50 ký tự.',
            'bio.required' => 'Giới thiệu bản thân là bắt buộc.',
            'bio.max' => 'Giới thiệu bản thân không được vượt quá 1000 ký tự.',
            'reason.required' => 'Lý do đăng ký Creator là bắt buộc.',
            'reason.max' => 'Lý do đăng ký không được vượt quá 1000 ký tự.',
            'sample_works.max' => 'Tối đa 3 liên kết tác phẩm mẫu.',
            'sample_works.*.url' => 'Liên kết tác phẩm mẫu phải là URL hợp lệ.',
        ];
    }
}
