<?php

namespace App\Http\Requests;

use App\Models\Chapter;
use Illuminate\Foundation\Http\FormRequest;

class ReorderChapterImagesRequest extends FormRequest
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

        return $this->user()?->can('manageImages', $chapter) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'image_ids' => ['sometimes', 'required_without:orders', 'array', 'min:1'],
            'image_ids.*' => ['integer'],
            'orders' => ['sometimes', 'required_without:image_ids', 'array', 'min:1'],
            'orders.*.id' => ['required_with:orders', 'integer'],
            'orders.*.page_number' => ['required_with:orders', 'integer', 'min:1'],
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
            'image_ids' => 'thứ tự ID ảnh',
            'orders' => 'danh sách sắp xếp ảnh',
        ];
    }
}
