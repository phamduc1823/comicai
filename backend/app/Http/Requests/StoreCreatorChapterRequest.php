<?php

namespace App\Http\Requests;

use App\Models\Chapter;
use App\Models\Comic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreatorChapterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $comicId = $this->route('comicId') ?? $this->route('comic');
        $comic = $comicId instanceof Comic ? $comicId : Comic::find($comicId);

        if (! $comic) {
            return false;
        }

        return $this->user()?->can('create', [Chapter::class, $comic]) ?? false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('chapter_number') && is_numeric($this->input('chapter_number'))) {
            $this->merge([
                'chapter_number' => sprintf('%.2f', (float) $this->input('chapter_number')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $comicId = $this->route('comicId') ?? $this->route('comic');

        return [
            'chapter_number' => [
                'required',
                'numeric',
                'min:0',
                'max:9999.99',
                'regex:/^[0-9]{1,4}(\.[0-9]{1,2})?$/',
                Rule::unique('chapters', 'chapter_number')->where('comic_id', $comicId),
            ],
            'title' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Custom attribute names for validation error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'chapter_number' => 'số chương',
            'title' => 'tiêu đề chương',
        ];
    }

    /**
     * Custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'chapter_number.unique' => 'Số chương này đã tồn tại trong bộ truyện.',
        ];
    }
}
