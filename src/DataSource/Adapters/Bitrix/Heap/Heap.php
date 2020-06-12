<?php

declare(strict_types=1);

namespace spaceonfire\DataSource\Adapters\Bitrix\Heap;

use IteratorAggregate;
use spaceonfire\DataSource\EntityInterface;
use SplObjectStorage;
use UnexpectedValueException;

final class Heap implements IteratorAggregate
{
    /**
     * @var SplObjectStorage
     */
    private $storage;

    /**
     * Heap constructor.
     */
    public function __construct()
    {
        $this->storage = new SplObjectStorage();
    }

    public function has(EntityInterface $entity): bool
    {
        return $this->storage->offsetExists($entity);
    }

    public function get(EntityInterface $entity): ?array
    {
        try {
            return $this->storage->offsetGet($entity);
        } catch (UnexpectedValueException $e) {
            return null;
        }
    }

    /**
     * @param EntityInterface $entity
     * @param array $data
     */
    public function attach(EntityInterface $entity, array $data): void
    {
        $this->storage->offsetSet($entity, $data);
    }

    /**
     * @param EntityInterface $entity
     */
    public function detach(EntityInterface $entity): void
    {
        $this->storage->offsetUnset($entity);
    }

    /**
     * @inheritDoc
     * @return SplObjectStorage
     */
    public function getIterator(): SplObjectStorage
    {
        return $this->storage;
    }
}
