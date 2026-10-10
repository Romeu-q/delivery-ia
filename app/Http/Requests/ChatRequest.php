<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Remove caracteres de controle e espaços excedentes antes de validar
        $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $this->input('message'));

        $this->merge(['message' => trim(preg_replace('/[ \t]+/', ' ', $message))]);
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:'.config('ai.max_question_length')],
            'conversation_id' => ['nullable', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.required' => 'Digite uma pergunta.',
            'message.min' => 'A pergunta está muito curta.',
            'message.max' => 'A pergunta pode ter no máximo :max caracteres.',
            'conversation_id.uuid' => 'Conversa inválida.',
        ];
    }
}
