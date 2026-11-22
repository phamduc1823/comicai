<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * AI search is publicly accessible to all readers and guests.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation (normalize age_rating if unencoded plus becomes space).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('age_rating') && is_string($this->age_rating)) {
            $val = trim($this->age_rating);
            if (in_array($val, ['13', '16', '18'], true)) {
                $this->merge(['age_rating' => $val . '+']);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:300'],
            'mode' => ['sometimes', 'nullable', 'string', 'in:normal,ai'],
            'genre' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:100'],
            'age_rating' => ['sometimes', 'nullable', 'string', 'in:all,13+,16+,18+'],
            'status' => ['sometimes', 'nullable', 'string', 'in:ongoing,completed,hiatus'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * Custom attribute names for user-friendly validation error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'câu truy vấn tìm kiếm',
            'genre' => 'thể loại',
            'tag' => 'nhãn đặc điểm',
            'age_rating' => 'độ tuổi',
            'status' => 'trạng thái truyện',
            'limit' => 'số lượng kết quả',
        ];
    }
}
