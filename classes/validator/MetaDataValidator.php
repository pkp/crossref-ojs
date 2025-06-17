<?php

namespace APP\plugins\generic\crossref\classes\validator;

use APP\facades\Repo;
use APP\plugins\generic\crossref\classes\dto\PublicationMetadata;
use APP\submission\Submission;
use Illuminate\Validation\Validator;
use PKP\context\Context;
use PKP\validation\ValidatorFactory;

class MetaDataValidator
{
    private Submission $submission;
    private Context $context;
    private ?Validator $validator = null;

    public function __construct(Submission $submission, Context $context)
    {
        $this->submission = $submission;
        $this->context = $context;
    }

    public function metaDataValidation(): void
    {
        $this->validator = ValidatorFactory::make(
            $this->metaData()->toArray(),
            $this->getValidationRules(),
            $this->getValidationMessages()
        );
    }

    /**
     * create meta data array for validate
     * only for Crossref plugin
     * @return PublicationMetadata
     */
    private function metaData(): PublicationMetadata
    {
        $publication = $this->submission->getCurrentPublication();
        $issueId = $publication->getData('issueId');

        return new PublicationMetadata(
            title: $publication->getData('title'),
            printIssn: $this->context->getData('printIssn'),
            onlineIssn: $this->context->getData('onlineIssn'),
            doi: $publication->getDoi(),
            dateSubmitted: $this->submission->getData('dateSubmitted'),
            journalTitle: $this->context->getData('name'),
            issue: $issueId,
        );
    }

    public function getValidationRules(): array
    {
        $primaryLocale = $this->context->getPrimaryLocale();
        return [
            "printIssn" => [
                'required',
                'string',
            ],
            "onlineIssn" => [
                'required',
                'string',
            ],
            "title" => [
                'required',
                'array',
            ],
            "title.{$primaryLocale}" => [
                'required',
                'string',
            ],
            "journalTitle" => [
                'required',
                'array',
            ],
            "journalTitle.{$primaryLocale}" => [
                'required',
                'string',
            ],
            "issue" => [
                'required',
                'integer',
            ],
        ];
    }

    public function getValidationMessages(): array
    {
        return [
            'issue.required' => 'issue required',
            'onlineIssn.required' => 'onlineIssn required',
            'printIssn.required' => 'printIssn required',
            'dateSubmitted.required' => 'dateSubmitted required',
            'title.required' => 'title required',
            'title.en.required' => 'title.en required',
            'journalTitle.required' => 'journalTitle required',
            'journalTitle.en.required' => 'journalTitle en required',
        ];
    }

    public function isValid(): bool
    {
        return !$this->validator->fails();
    }

    public function getErrors(): array
    {
        return $this->arrayValuesRecursive($this->validator->errors()->toArray());
    }

    /**
     * get only array values as an array
     * @param $array
     * @return array
     */
    private function arrayValuesRecursive($array): array
    {
        $values = [];
        foreach ($array as $value) {
            if (is_array($value)) {
                $values = array_merge($values, $this->arrayValuesRecursive($value));
            } else {
                $values[] = $value;
            }
        }
        return $values;
    }
}
