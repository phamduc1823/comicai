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
     * Prepare inputs for validation (normalize age_rating if unencoded plus becomes space, normalize arrays/commas).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('age_rating') && is_string($this->age_rating)) {
            $val = trim($this->age_rating);
            if (in_array($val, ['13', '16', '18'], true)) {
                $this->merge(['age_rating' => $val . '+']);
            }
        }

        $normalize = function ($value) {
            if (is_array($value)) {
                return array_values(array_filter(array_map('trim', $value), fn ($v) => $v !== ''));
            }
            if (is_string($value) && str_contains($value, ',')) {
                return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
            }
            return $value;
        };

        $data = [];
        foreach (['genre', 'tag', 'status'] as $key) {
            if ($this->has($key)) {
                $data[$key] = $normalize($this->input($key));
            }
        }
        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $genreRules = is_array($this->input('genre'))
            ? ['genre' => ['sometimes', 'nullable', 'array'], 'genre.*' => ['string', 'max:100']]
            : ['genre' => ['sometimes', 'nullable', 'string', 'max:100']];

        $tagRules = is_array($this->input('tag'))
            ? ['tag' => ['sometimes', 'nullable', 'array'], 'tag.*' => ['string', 'max:100']]
            : ['tag' => ['sometimes', 'nullable', 'string', 'max:100']];

        $statusRules = is_array($this->input('status'))
            ? ['status' => ['sometimes', 'nullable', 'array'], 'status.*' => ['string', 'in:ongoing,completed,hiatus']]
            : ['status' => ['sometimes', 'nullable', 'string', 'in:ongoing,completed,hiatus']];

        return array_merge([
            'q' => ['required', 'string', 'min:2', 'max:300'],
            'mode' => ['sometimes', 'nullable', 'string', 'in:normal,ai'],
            'age_rating' => ['sometimes', 'nullable', 'string', 'in:all,13+,16+,18+'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
        ], $genreRules, $tagRules, $statusRules);
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
