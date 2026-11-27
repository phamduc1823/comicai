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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chapter_number' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                'max:999999',
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
            'chapter_number.integer' => 'Số thứ tự chương phải là số nguyên dương (không sử dụng số thập phân).',
            'chapter_number.min' => 'Số thứ tự chương phải lớn hơn hoặc bằng 1.',
            'chapter_number.unique' => 'Số chương này đã tồn tại trong bộ truyện.',
        ];
    }
}
