<?php

namespace APP\plugins\generic\crossref\classes\validator;

use APP\plugins\generic\crossref\classes\dto\PublicationMetadata;
use APP\submission\Submission;
use Illuminate\Validation\Validator;
use PKP\context\Context;
use PKP\validation\ValidatorFactory;

class MetadataValidator
{
    private Submission $submission;
    private Context $context;
    private ?Validator $validator = null;
    private ?string $primaryLocale = null;

    public function __construct(Submission $submission, Context $context)
    {
        $this->submission = $submission;
        $this->context = $context;
        $this->primaryLocale = $this->context->getPrimaryLocale();
    }

    public function metadataValidation(): void
    {
        $this->validator = ValidatorFactory::make(
            $this->formatMetadata()->toArray(),
            $this->getValidationRules(),
            $this->getValidationMessages()
        );
    }

    /**
     * create meta data array for validate
     * only for Crossref plugin
     * @return PublicationMetadata
     */
    private function formatMetadata(): PublicationMetadata
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
            "title.{$this->primaryLocale}" => [
                'required',
                'string',
            ],
            "journalTitle" => [
                'required',
                'array',
            ],
            "journalTitle.{$this->primaryLocale}" => [
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
            'issue.required' => __('plugins.generic.crossref.issue.required'),
            'onlineIssn.required' => __('plugins.generic.crossref.onlineIssn.required'),
            'printIssn.required' => __('plugins.generic.crossref.printIssn.required'),
            'dateSubmitted.required' => __('plugins.generic.crossref.dateSubmitted.required'),
            'title.required' => __('plugins.generic.crossref.title.required'),
            'title.' . $this->primaryLocale . '.required' => __('plugins.generic.crossref.title.'. $this->primaryLocale .'.required'),
            'journalTitle.required' => __('plugins.generic.crossref.journalTitle.required'),
            'journalTitle.' . $this->primaryLocale . '.required' => __('plugins.generic.crossref.'. $this->primaryLocale .'.required'),
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
