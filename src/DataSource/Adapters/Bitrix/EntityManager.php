<?php

declare(strict_types=1);

namespace spaceonfire\DataSource\Adapters\Bitrix;

use RuntimeException;
use spaceonfire\Collection\CollectionInterface;
use spaceonfire\Collection\IndexedCollection;
use spaceonfire\Collection\TypedCollection;
use spaceonfire\Criteria\CriteriaInterface;
use spaceonfire\DataSource\EntityInterface;
use spaceonfire\DataSource\MapperInterface;
use spaceonfire\DataSource\RepositoryInterface;
use spaceonfire\Type\InstanceOfType;
use SplObjectStorage;
use UnexpectedValueException;
use Webmozart\Assert\Assert;

final class EntityManager implements EntityManagerInterface
{
    /**
     * @var SplObjectStorage<object,mixed>|mixed[]
     */
    private $heap;
    /**
     * @var array<string,RepositoryInterface>|RepositoryInterface[]
     */
    private $roleToRepositoryMap = [];
    /**
     * @var array<string,string|EntityInterface>|string[]
     */
    private $roleToEntityClassMap = [];
    /**
     * @var array<string,CollectionInterface|EntityInterface[]>
     */
    private $collections = [];
    /**
     * @var array
     */
    private $indexes = [];

    /**
     * EntityManager constructor.
     */
    public function __construct()
    {
        $this->heap = new SplObjectStorage();
    }

    /**
     * @inheritDoc
     */
    public function resolveRole($role): string
    {
        if ($role instanceof MapperInterface) {
            $role = $this->heap[$role];
        }

        if ($role instanceof RepositoryInterface) {
            return $this->heap[$role];
        }

        if ($role instanceof EntityInterface) {
            $role = get_class($role);
        }

        if (is_subclass_of($role, EntityInterface::class)) {
            $entityClassRoleMap = array_flip($this->roleToEntityClassMap);

            if (isset($entityClassRoleMap[$role])) {
                return $entityClassRoleMap[$role];
            }

            foreach ($this->roleToEntityClassMap as $r => $entityClass) {
                if (is_subclass_of($role, $entityClass)) {
                    return $r;
                }
            }
        }

        if (is_string($role)) {
            if (!isset($this->roleToRepositoryMap[$role])) {
                throw new RuntimeException(sprintf('No repository registered for role "%s"', $role));
            }

            return $role;
        }

        throw new RuntimeException(sprintf('Unable to resolve role from "%s"', get_debug_type($role)));
    }

    /**
     * @inheritDoc
     */
    public function registerRepository(RepositoryInterface $repository, ?string $role = null): void
    {
        /** @var AbstractMapper $mapper */
        Assert::isInstanceOf($mapper = $repository->getMapper(), AbstractMapper::class);
        $entityClass = $mapper->resolveClass([]);

        $role = $role ?? $entityClass;

        if (isset($this->roleToRepositoryMap[$role])) {
            if ($this->roleToRepositoryMap[$role] === $repository) {
                return;
            }

            throw new RuntimeException(sprintf('Repository already registered for role "%s"', $role));
        }

        $this->roleToRepositoryMap[$role] = $repository;
        $this->roleToEntityClassMap[$role] = $entityClass;
        $this->indexes[$role] = [];

        $this->heap[$repository] = $role;
        $this->heap[$mapper] = $repository;
    }

    /**
     * @inheritDoc
     */
    public function attachEntity(EntityInterface $entity, array $data): void
    {
        $role = $this->resolveRole($entity);

        // Attach data to heap
        $this->heap->offsetSet($entity, $data);

        // Attach entity to collection
        $this->getEntityCollection($role)->offsetSet(null, $entity);

        // Index entity
        [$indexKey, $indexValue] = $this->createIndex($role, $data);
        if (!empty($indexKey) && !empty($indexValue)) {
            $this->indexes[$role][$indexKey][$indexValue] = $entity;
        }
    }

    private function createIndex($role, array $data): array
    {
        $role = $this->resolveRole($role);
        $mapper = $this->getMapper($role);

        $indexKey = array_map(static function (string $key) use ($mapper) {
            return $mapper->convertNameToStorage($key);
        }, $mapper->getUniqueIndexes());
        $indexValue = [];
        foreach ($indexKey as $k) {
            $indexValue[] = $data[$k];
        }

        $indexKey = implode(',', $indexKey);
        $indexValue = implode(',', $indexValue);

        return [$indexKey, $indexValue];
    }

    private function getEntityCollection($role): CollectionInterface
    {
        $role = $this->resolveRole($role);

        if (!isset($this->collections[$role])) {
            $this->collections[$role] = new TypedCollection(
                new IndexedCollection([], static function ($object) {
                    return spl_object_hash($object);
                }),
                new InstanceOfType($this->roleToEntityClassMap[$role])
            );
        }

        return $this->collections[$role];
    }

    /**
     * @inheritDoc
     */
    public function detachEntity(EntityInterface $entity): void
    {
        $role = $this->resolveRole($entity);

        // Unset entity index
        [$indexKey, $indexValue] = $this->createIndex($role, $this->getEntityData($entity));
        unset($this->indexes[$role][$indexKey][$indexValue]);

        // Remove entity from collection
        $hash = spl_object_hash($entity);
        $this->getEntityCollection($role)->offsetUnset($hash);

        // Remove data from heap
        $this->heap->offsetUnset($entity);
    }

    /**
     * @inheritDoc
     */
    public function getEntityData(EntityInterface $entity): ?array
    {
        try {
            return $this->heap->offsetGet($entity);
        } catch (UnexpectedValueException $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function getRepository($role): RepositoryInterface
    {
        $role = $this->resolveRole($role);
        return $this->roleToRepositoryMap[$role];
    }

    /**
     * @inheritDoc
     */
    public function getMapper($role): MapperInterface
    {
        $role = $this->resolveRole($role);
        return $this->roleToRepositoryMap[$role]->getMapper();
    }

    /**
     * @inheritDoc
     */
    public function getByIndex($role, array $data): ?EntityInterface
    {
        $role = $this->resolveRole($role);

        [$indexKey, $indexValue] = $this->createIndex($role, $data);

        if (!empty($indexKey) && !empty($indexValue)) {
            return $this->indexes[$role][$indexKey][$indexValue] ?? null;
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function make($role, array $data): EntityInterface
    {
        $role = $this->resolveRole($role);

        if (null !== $entity = $this->getByIndex($role, $data)) {
            return $entity;
        }

        $mapper = $this->getMapper($role);

        [$entity, $data] = $mapper->init($data);

        if (!$entity instanceof EntityInterface) {
            throw new RuntimeException('Associated with repository class must implement ' . EntityInterface::class);
        }

        $mapper->hydrate($entity, $data);

        $this->attachEntity($entity, $data);

        return $entity;
    }

    /**
     * @inheritDoc
     */
    public function getByCriteria($role, CriteriaInterface $criteria): ?EntityInterface
    {
        if (null === $expr = $criteria->getWhere()) {
            return null;
        }

        $role = $this->resolveRole($role);

        $collection = $this->getEntityCollection($role);

        return $collection->find(static function ($object) use ($expr) {
            return $expr->evaluate($object);
        });
    }
}
