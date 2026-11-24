<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && (bool) $this->user()->is_active;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'unread_only' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => 'Tham số page phải là số nguyên.',
            'page.min' => 'Tham số page phải lớn hơn hoặc bằng 1.',
            'per_page.integer' => 'Tham số per_page phải là số nguyên.',
            'per_page.min' => 'Tham số per_page phải lớn hơn hoặc bằng 1.',
            'per_page.max' => 'Tham số per_page không được vượt quá 50.',
            'unread_only.boolean' => 'Tham số unread_only phải là giá trị boolean.',
        ];
    }

    public function getPerPage(): int
    {
        return (int) $this->validated('per_page', 15);
    }

    public function getUnreadOnly(): bool
    {
        return (bool) $this->validated('unread_only', false);
    }
}
