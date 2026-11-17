<?php

namespace App\Http\Requests;

use App\Models\Report;
use Illuminate\Foundation\Http\FormRequest;

class UpdateReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'admin';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                'in:resolved,dismissed',
            ],
            'admin_notes' => [
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
            if (is_array($rawJson) && array_key_exists('admin_notes', $rawJson) && is_string($rawJson['admin_notes'])) {
                if (trim($rawJson['admin_notes']) === '') {
                    $validator->errors()->add('admin_notes', 'Ghi chú của quản trị viên không được chỉ chứa khoảng trắng.');
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
            'status.required' => 'Vui lòng cung cấp trạng thái xử lý báo cáo.',
            'status.in' => 'Trạng thái xử lý không hợp lệ. Chỉ chấp nhận: resolved hoặc dismissed.',
            'admin_notes.max' => 'Ghi chú của quản trị viên không được vượt quá 1000 ký tự.',
        ];
    }
}
