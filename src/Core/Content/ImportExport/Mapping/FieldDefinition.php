<?php declare(strict_types=1);

namespace Shopware\Core\Content\ImportExport\Mapping;

use Shopware\Core\Framework\Struct\Struct;

class FieldDefinition extends Struct
{
    /**
     * @var string
     */
    protected $fileField;

    /**
     * @var string
     */
    protected $entityField;

    /**
     * @var array
     */
    protected $valueSubstitutions;

    public function __construct(?string $fileField = null, ?string $entityField = null, array $valueSubstitutions = [])
    {
        if ($fileField !== null) {
            $this->setFileField($fileField);
        }
        if ($entityField !== null) {
            $this->setEntityField($entityField);
        }
        $this->setValueSubstitutions($valueSubstitutions);
    }

    public function getFileField(): string
    {
        return $this->fileField;
    }

    public function setFileField(string $fileField): void
    {
        $this->fileField = $fileField;
    }

    public function getEntityField(): string
    {
        return $this->entityField;
    }

    public function setEntityField(string $entityField): void
    {
        $this->entityField = $entityField;
    }

    public function getValueSubstitutions(): array
    {
        return $this->valueSubstitutions;
    }

    public function setValueSubstitutions(array $valueSubstitutions): void
    {
        if (count(array_keys($valueSubstitutions)) !== count(array_unique($valueSubstitutions))) {
            throw new \RuntimeException('FieldDefinition only supports bijective value substitutions');
        }
        $this->valueSubstitutions = $valueSubstitutions;
    }
}
