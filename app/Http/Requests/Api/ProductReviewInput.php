<?php

namespace App\Http\Requests\Api;

trait ProductReviewInput
{
    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        $comment = $this->input('comment');

        $normalized = [];
        if (is_string($title)) {
            $normalized['title'] = preg_replace('/[\t ]+/u', ' ', trim($title));
        }
        if (is_string($comment)) {
            $comment = str_replace(["\r\n", "\r"], "\n", $comment);
            $comment = preg_replace('/[\t ]+/u', ' ', $comment);
            $normalized['comment'] = trim((string) $comment);
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120', 'regex:/\A[^<>]*\z/u'],
            'comment' => ['required', 'string', 'min:20', 'max:1000', 'regex:/\A[^<>]*\z/u'],
        ];
    }
}
