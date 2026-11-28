<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexConversationsRequest extends FormRequest
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
            'page' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:50',
            ],
            'tab' => [
                'sometimes',
                'string',
                'in:main,requests',
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
            'page.integer' => 'Số trang phải là số nguyên.',
            'page.min' => 'Số trang tối thiểu là 1.',
            'per_page.integer' => 'Số bản ghi mỗi trang phải là số nguyên.',
            'per_page.min' => 'Số bản ghi mỗi trang tối thiểu là 1.',
            'per_page.max' => 'Số bản ghi mỗi trang tối đa là 50.',
        ];
    }

    /**
     * Helper to get per_page with default fallback of 15.
     */
    public function getPerPage(): int
    {
        return (int) $this->input('per_page', 15);
    }
}
