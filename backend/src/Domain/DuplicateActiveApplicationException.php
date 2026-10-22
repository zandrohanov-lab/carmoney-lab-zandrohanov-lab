<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

use RuntimeException;

/**
 * У клиента уже есть активная заявка, поэтому новую создать нельзя.
 *
 * Активная заявка — та, у которой status != 'cancelled'.
 * Ограничение держит база: UNIQUE по вычисляемой колонке active_ref,
 * поэтому проверки в коде нет — дубликат приходит из СУБД как 1062,
 * а этот класс переводит его в понятную доменную ошибку (HTTP 409).
 */
final class DuplicateActiveApplicationException extends RuntimeException
{
    public function __construct(private readonly string $applicantRef)
    {
        parent::__construct(sprintf('У клиента %s уже есть активная заявка', $applicantRef));
    }

    public function applicantRef(): string
    {
        return $this->applicantRef;
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return ['applicant_ref' => 'По этому клиенту уже есть активная заявка'];
    }
}