<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
            'conversation_id' => ['nullable', 'exists:conversations,id'],
            'session_id' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
{
    $this->merge([
        'message' => strip_tags($this->input('message')), // Remove any HTML Tags
    ]);
}
}
