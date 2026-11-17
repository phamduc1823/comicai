<?php

namespace App\Http\Requests;

use App\Models\Comment;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Comment::class) ?? false;
    }

    /**
     * Prepare the data for validation.
     * Extracts route parameters when using dedicated routes.
     */
    protected function prepareForValidation(): void
    {
        $route = $this->route();
        if ($route) {
            $id = $route->parameter('id');
            $uri = $route->uri();

            if (str_contains($uri, 'posts/{id}/comments')) {
                $this->merge([
                    'commentable_type' => 'post',
                    'commentable_id' => $id,
                ]);
            } elseif (str_contains($uri, 'comics/{id}/comments')) {
                $this->merge([
                    'commentable_type' => 'comic',
                    'commentable_id' => $id,
                ]);
            } elseif (str_contains($uri, 'chapters/{id}/comments')) {
                $this->merge([
                    'commentable_type' => 'chapter',
                    'commentable_id' => $id,
                ]);
            } elseif (str_contains($uri, 'comments/{id}/reply')) {
                $this->merge([
                    'parent_id' => $id,
                ]);
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
            'content' => [
                'required',
                'string',
                'max:2000',
                function ($attribute, $value, $fail) {
                    if (trim($value) === '') {
                        $fail('Nội dung bình luận không được để trống hoặc chỉ chứa khoảng trắng.');
                    }
                },
            ],
            'commentable_type' => [
                'nullable',
                'string',
                'in:post,comic,chapter',
            ],
            'commentable_id' => [
                'nullable',
                'integer',
            ],
            'parent_id' => [
                'nullable',
                'integer',
            ],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $parentId = $this->input('parent_id');
            $type = $this->input('commentable_type');
            $id = $this->input('commentable_id');

            // If not replying, target entity type and id are required
            if (! $parentId && (! $type || ! $id)) {
                $validator->errors()->add('commentable_type', 'Vui lòng xác định đối tượng cần bình luận.');
            }
        });
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'Nội dung bình luận không được để trống.',
            'content.max' => 'Nội dung bình luận không được vượt quá 2000 ký tự.',
            'commentable_type.in' => 'Loại đối tượng bình luận không hợp lệ. Chỉ chấp nhận post, comic hoặc chapter.',
            'parent_id.exists' => 'Bình luận cha không tồn tại.',
        ];
    }
}
