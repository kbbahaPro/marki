<?php

namespace App\Enums;

enum QuestionType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Phone = 'phone';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('Short answer'),
            self::Textarea => __('Paragraph'),
            self::Select => __('Dropdown'),
            self::Checkbox => __('Checkbox'),
            self::Phone => __('Phone number'),
        };
    }

    public function hasOptions(): bool
    {
        return $this === self::Select;
    }
}
