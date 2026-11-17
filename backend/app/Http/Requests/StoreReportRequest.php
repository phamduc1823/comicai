<?php

namespace App\Http\Requests;

use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;

class StoreReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Report::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reportable_type' => [
                'required',
                'string',
                'in:post,comment,comic,chapter,user',
            ],
            'reportable_id' => [
                'required',
                'integer',
            ],
            'reason' => [
                'required',
                'string',
                'max:50',
                function ($attribute, $value, $fail) {
                    if (is_string($value) && trim($value) === '') {
                        $fail('Lý do báo cáo không được để trống hoặc chỉ chứa khoảng trắng.');
                    }
                },
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rawJson = json_decode($this->getContent(), true);
            if (is_array($rawJson) && array_key_exists('description', $rawJson) && is_string($rawJson['description'])) {
                if (trim($rawJson['description']) === '') {
                    $validator->errors()->add('description', 'Mô tả chi tiết không được chỉ chứa khoảng trắng.');
                }
            }
        });
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reportable_type.required' => 'Vui lòng xác định loại đối tượng cần báo cáo.',
            'reportable_type.in' => 'Loại đối tượng báo cáo không hợp lệ. Chỉ chấp nhận: post, comment, comic, chapter, user.',
            'reportable_id.required' => 'Vui lòng cung cấp ID đối tượng cần báo cáo.',
            'reportable_id.integer' => 'ID đối tượng báo cáo phải là số nguyên.',
            'reason.required' => 'Vui lòng chọn hoặc nhập lý do báo cáo.',
            'reason.max' => 'Lý do báo cáo không được vượt quá 50 ký tự.',
            'description.max' => 'Mô tả chi tiết không được vượt quá 1000 ký tự.',
        ];
    }
}
