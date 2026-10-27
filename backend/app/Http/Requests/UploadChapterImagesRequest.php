<?php

namespace App\Http\Requests;

use App\Models\Chapter;
use Illuminate\Foundation\Http\FormRequest;

class UploadChapterImagesRequest extends FormRequest
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
            'images' => ['sometimes', 'required_without:image', 'array', 'min:1', 'max:100'],
            'images.*' => [
                'file',
                'image',
                'mimes:jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:10240', // 10MB
            ],
            'image' => [
                'sometimes',
                'required_without:images',
                'file',
                'image',
                'mimes:jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:10240', // 10MB
            ],
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
            'images' => 'danh sách ảnh chương',
            'images.*' => 'ảnh trang truyện',
            'image' => 'ảnh trang truyện',
        ];
    }
}
