<?php

namespace App\Http\Requests;

use App\Models\Comic;
use Illuminate\Foundation\Http\FormRequest;

class StoreCreatorComicRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Comic::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:200'],
            'author_name' => ['required', 'string', 'min:2', 'max:150'],
            'cover' => [
                'required',
                'file',
                'image',
                'mimes:jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:5120',
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'alternative_titles' => ['nullable', 'string', 'max:255'],
            'age_rating' => ['nullable', 'string', 'in:all,13+,16+,18+'],
            'status' => ['nullable', 'string', 'in:ongoing,completed'],
            'genres' => ['required', 'array', 'min:1'],
            'genres.*' => ['integer', 'exists:genres,id'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['integer', 'exists:tags,id'],
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
            'title' => 'tiêu đề truyện',
            'author_name' => 'tên tác giả',
            'cover' => 'ảnh bìa',
            'description' => 'mô tả truyện',
            'genres' => 'thể loại',
            'tags' => 'nhãn đặc điểm',
        ];
    }
}
