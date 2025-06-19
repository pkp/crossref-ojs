<?php

namespace APP\plugins\generic\crossref\classes\dto;

class PublicationMetadata
{
    public function __construct(
        public ?array $title,
        public ?string $printIssn,
        public ?string $onlineIssn,
        public ?string $doi,
        public ?string $dateSubmitted,
        public ?array $journalTitle,
        public ?int $issue,
    ) {}

    /**
     * Convert the instance to an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
