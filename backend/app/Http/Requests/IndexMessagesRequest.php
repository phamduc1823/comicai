<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexMessagesRequest extends FormRequest
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
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:50',
            ],
            'before_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'prohibits:after_id',
            ],
            'after_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'prohibits:before_id',
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
            'limit.integer' => 'Số lượng tin nhắn phải là số nguyên.',
            'limit.min' => 'Số lượng tin nhắn tối thiểu là 1.',
            'limit.max' => 'Số lượng tin nhắn tối đa trong một yêu cầu là 50.',
            'before_id.integer' => 'before_id phải là số nguyên dương.',
            'before_id.min' => 'before_id phải lớn hơn hoặc bằng 1.',
            'before_id.prohibits' => 'Không thể sử dụng before_id và after_id trong cùng một yêu cầu.',
            'after_id.integer' => 'after_id phải là số nguyên dương.',
            'after_id.min' => 'after_id phải lớn hơn hoặc bằng 1.',
            'after_id.prohibits' => 'Không thể sử dụng after_id và before_id trong cùng một yêu cầu.',
        ];
    }

    /**
     * Helper to get validated or default limit.
     */
    public function getLimit(): int
    {
        return (int) $this->input('limit', 25);
    }
}
