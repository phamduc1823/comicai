<?php

namespace App\Http\Requests;

use App\Models\Chapter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCreatorChapterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $chapterId = $this->route('id') ?? $this->route('chapter');
        $chapter = $chapterId instanceof Chapter ? $chapterId : Chapter::find($chapterId);

        if (! $chapter) {
            return false;
        }

        return $this->user()?->can('update', $chapter) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $chapterId = $this->route('id') ?? $this->route('chapter');
        $chapter = $chapterId instanceof Chapter ? $chapterId : Chapter::find($chapterId);
        $comicId = $chapter?->comic_id;

        return [
            'chapter_number' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:999999',
                Rule::unique('chapters', 'chapter_number')->where('comic_id', $comicId)->ignore($chapterId),
            ],
            'title' => ['nullable', 'string', 'max:150'],
            'is_vip' => ['sometimes', 'boolean'],
            'price' => ['sometimes', 'nullable', 'integer', 'min:' . config('vip.price_min', 5), 'max:' . config('vip.price_max', 50)],
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
