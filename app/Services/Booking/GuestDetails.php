<?php

namespace App\Services\Booking;

/**
 * What a guest submits on the booking form, already validated.
 */
final class GuestDetails
{
    /**
     * @param  list<array{question_id: ?int, label: string, value: ?string, position: int}>  $answers
     */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $timezone,
        public readonly ?string $notes = null,
        public readonly ?string $phone = null,
        public readonly array $answers = [],
    ) {}
}
