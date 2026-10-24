<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\Username;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'username' => [
                'required',
                'string',
                new Username,
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'timezone' => ['required', 'timezone:all'],
            'job_title' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:100'],
            // Re-encoded server-side (AvatarService), so only the input format matters here.
            'avatar' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=100,min_height=100,max_width=8000,max_height=8000'],
            'remove_avatar' => ['sometimes', 'boolean'],
        ];
    }
}
