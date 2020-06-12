<?php

declare(strict_types=1);

namespace spaceonfire\DataSource\Adapters\Bitrix;

use Doctrine\Instantiator;
use Laminas\Hydrator;
use spaceonfire\DataSource\MapperInterface;

abstract class AbstractMapper implements MapperInterface
{
    /** @var Hydrator\HydratorInterface|Hydrator\AbstractHydrator */
    protected $hydrator;

    /** @var Instantiator\InstantiatorInterface */
    protected $instantiator;

    public function __construct()
    {
        $this->hydrator = new Hydrator\ReflectionHydrator();
        $this->instantiator = new Instantiator\Instantiator();
    }

    /**
     * Resolve entity class by given data
     * @param array<string,mixed> $data
     * @return string
     */
    abstract protected function resolveClass(array $data): string;

    /**
     * @inheritDoc
     */
    public function init(array $data): array
    {
        $class = $this->resolveClass($data);

        return [$this->instantiator->instantiate($class), $data];
    }

    /**
     * @inheritDoc
     */
    public function hydrate($entity, array $data)
    {
        return $this->hydrator->hydrate($data, $entity);
    }

    /**
     * @inheritDoc
     */
    public function extract($entity): array
    {
        return $this->hydrator->extract($entity);
    }

    /**
     * @inheritDoc
     */
    public function convertValueToDomain(string $fieldName, $storageValue)
    {
        return $this->hydrator->hydrateValue($fieldName, $storageValue);
    }

    /**
     * @inheritDoc
     */
    public function convertValueToStorage(string $fieldName, $domainValue)
    {
        return $this->hydrator->extractValue($fieldName, $domainValue);
    }

    /**
     * @inheritDoc
     */
    public function convertNameToDomain(string $fieldName): string
    {
        return $this->hydrator->hydrateName($fieldName);
    }

    /**
     * @inheritDoc
     */
    public function convertNameToStorage(string $fieldName): string
    {
        return $this->hydrator->extractName($fieldName);
    }
}
