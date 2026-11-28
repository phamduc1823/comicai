<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListComicsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare inputs for validation (normalize comma-separated strings or arrays).
     */
    protected function prepareForValidation(): void
    {
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
        foreach (['genre', 'genres', 'tag', 'tags', 'status', 'statuses', 'sort', 'sorts'] as $key) {
            if ($this->has($key)) {
                $data[$key] = $normalize($this->input($key));
            }
        }

        if ($this->has('featured') && ! $this->has('is_featured')) {
            $data['is_featured'] = $this->input('featured');
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
            ? ['genre' => ['sometimes', 'array'], 'genre.*' => ['string', 'max:100']]
            : ['genre' => ['sometimes', 'string', 'max:100']];

        $genresRules = is_array($this->input('genres'))
            ? ['genres' => ['sometimes', 'array'], 'genres.*' => ['string', 'max:100']]
            : ['genres' => ['sometimes', 'string', 'max:100']];

        $tagRules = is_array($this->input('tag'))
            ? ['tag' => ['sometimes', 'array'], 'tag.*' => ['string', 'max:100']]
            : ['tag' => ['sometimes', 'string', 'max:100']];

        $tagsRules = is_array($this->input('tags'))
            ? ['tags' => ['sometimes', 'array'], 'tags.*' => ['string', 'max:100']]
            : ['tags' => ['sometimes', 'string', 'max:100']];

        $statusRules = is_array($this->input('status'))
            ? ['status' => ['sometimes', 'array'], 'status.*' => ['string', 'in:ongoing,completed,hiatus']]
            : ['status' => ['sometimes', 'string', 'in:ongoing,completed,hiatus']];

        $statusesRules = is_array($this->input('statuses'))
            ? ['statuses' => ['sometimes', 'array'], 'statuses.*' => ['string', 'in:ongoing,completed,hiatus']]
            : ['statuses' => ['sometimes', 'string', 'in:ongoing,completed,hiatus']];

        $sortRules = is_array($this->input('sort'))
            ? ['sort' => ['sometimes', 'array'], 'sort.*' => ['string', 'in:latest,views,follows,updated,weekly_views']]
            : ['sort' => ['sometimes', 'string', 'in:latest,views,follows,updated,weekly_views']];

        $sortsRules = is_array($this->input('sorts'))
            ? ['sorts' => ['sometimes', 'array'], 'sorts.*' => ['string', 'in:latest,views,follows,updated,weekly_views']]
            : ['sorts' => ['sometimes', 'string', 'in:latest,views,follows,updated,weekly_views']];

        return array_merge([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'is_featured' => ['sometimes', 'boolean'],
            'featured' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:100'],
        ], $genreRules, $genresRules, $tagRules, $tagsRules, $statusRules, $statusesRules, $sortRules, $sortsRules);
    }
}
