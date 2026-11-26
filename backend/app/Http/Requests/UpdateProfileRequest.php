<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();
        $userId = $user?->id;
        $isVerified = $user?->hasVerifiedEmail();

        $emailRules = [
            'sometimes',
            'required',
            'string',
            'email',
            'max:255',
            Rule::unique('users', 'email')->ignore($userId),
        ];

        if ($isVerified) {
            $emailRules[] = function ($attribute, $value, $fail) use ($user) {
                if (strtolower(trim((string) $value)) !== strtolower(trim((string) $user->email))) {
                    $fail('Địa chỉ email đã được xác minh và không thể thay đổi.');
                }
            };
        }

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => $emailRules,
        ];
    }

    /**
     * Custom Vietnamese error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Họ và tên không được để trống.',
            'name.min' => 'Họ và tên phải có ít nhất 2 ký tự.',
            'name.max' => 'Họ và tên không được vượt quá 255 ký tự.',
            'email.required' => 'Địa chỉ email không được để trống.',
            'email.email' => 'Địa chỉ email không hợp lệ.',
            'email.unique' => 'Địa chỉ email này đã được sử dụng bởi tài khoản khác.',
        ];
    }
}
