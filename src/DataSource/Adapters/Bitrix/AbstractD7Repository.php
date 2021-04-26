<?php

declare(strict_types=1);

namespace spaceonfire\DataSource\Adapters\Bitrix;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\ORM\Entity;
use Bitrix\Main\SystemException;
use InvalidArgumentException;
use RuntimeException;
use spaceonfire\BitrixTools\ORMTools;
use spaceonfire\Collection\CollectionInterface;
use spaceonfire\Criteria\Criteria;
use spaceonfire\Criteria\CriteriaInterface;
use spaceonfire\DataSource\Adapters\Bitrix\Query\D7Query;
use spaceonfire\DataSource\EntityInterface;
use spaceonfire\DataSource\Exceptions\NotFoundException;
use spaceonfire\DataSource\Exceptions\RemoveException;
use spaceonfire\DataSource\Exceptions\SaveException;
use spaceonfire\DataSource\MapperInterface;
use spaceonfire\DataSource\QueryInterface;
use spaceonfire\DataSource\RepositoryInterface;

abstract class AbstractD7Repository implements RepositoryInterface
{
    /**
     * @var EntityManagerInterface
     */
    private $em;
    /**
     * @var bool
     */
    private $tryFindOneByCriteriaInEntityManagerFirst = false;

    /**
     * AbstractD7Repository constructor.
     * @param EntityManagerInterface $em
     */
    public function __construct(EntityManagerInterface $em)
    {
        $em->registerRepository($this, $this->getRole());
        $this->em = $em;
    }

    /**
     * Returns entity role
     * @return string|null
     */
    public function getRole(): ?string
    {
        return null;
    }

    /**
     * Enable option to try find entity in manager by criteria before making actual request to database in `findOne()`
     * @param bool $value
     */
    final protected function tryFindOneByCriteriaInEntityManagerFirst(bool $value = true): void
    {
        $this->tryFindOneByCriteriaInEntityManagerFirst = $value;
    }

    /**
     * @return string|DataManager
     */
    abstract protected function getDataManager(): string;

    /**
     * @return Entity
     */
    final protected function getD7Entity(): Entity
    {
        try {
            return $this->getDataManager()::getEntity();
        } catch (SystemException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    abstract public function getMapper(): MapperInterface;

    /**
     * Creates query
     */
    final protected function query(): QueryInterface
    {
        try {
            return new D7Query($this->getDataManager()::query(), $this->getMapper(), $this->em);
        } catch (SystemException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function save($entity): void
    {
        $oldData = $this->em->getEntityData($entity);
        $data = $this->getMapper()->extract($entity);

        [$primary, $changes] = $this->splitPrimaryAndChanges($data, $oldData);

        if ([] === $changes) {
            return;
        }

        $primary = ORMTools::wrapTransaction(function () use ($primary, $changes): array {
            $result = $primary
                ? $this->getDataManager()::update($primary, $changes)
                : $this->getDataManager()::add($changes);

            if (!$result->isSuccess()) {
                throw static::makeSaveException($result);
            }

            return $result->getPrimary();
        });

        $data = array_merge($oldData ?? [], $data, $primary);
        $this->em->attachEntity($entity, $data);
        $this->getMapper()->hydrate($entity, $data);
    }

    /**
     * @inheritDoc
     */
    public function remove($entity): void
    {
        $oldData = $this->em->getEntityData($entity);
        if ($oldData === null) {
            return;
        }

        $primary = ORMTools::extractPrimary($this->getD7Entity(), $oldData);

        ORMTools::wrapTransaction(function () use ($primary): void {
            $result = $this->getDataManager()::delete($primary);

            if (!$result->isSuccess()) {
                throw static::makeRemoveException($result);
            }
        });

        $this->em->detachEntity($entity);
    }

    /**
     * @inheritDoc
     */
    public function findByPrimary($primary)
    {
        $primaryKeys = array_values($this->getD7Entity()->getPrimaryArray());

        $primary = is_array($primary) ? $primary : [$primary];

        if (count($primaryKeys) !== count($primary)) {
            throw new InvalidArgumentException('Invalid primary');
        }

        $criteria = new Criteria();
        $expr = Criteria::expr();
        $mapper = $this->getMapper();
        $indexData = [];

        foreach ($primaryKeys as $i => $key) {
            $domainKey = $mapper->convertNameToDomain($key);

            foreach ([$domainKey, $key, $i] as $k) {
                if (isset($primary[$k])) {
                    $val = $primary[$k];
                    break;
                }
            }

            if (!isset($val)) {
                throw new InvalidArgumentException('Invalid primary');
            }

            $criteria->andWhere($expr->property($domainKey, $expr->same($val)));
            $indexData[$key] = $mapper->convertValueToStorage($domainKey, $val);
        }

        if (null !== $entity = $this->em->getByIndex($this, $indexData)) {
            return $entity;
        }

        $entity = $this->findOne($criteria);

        if ($entity === null) {
            throw static::makeNotFoundException($primary);
        }

        return $entity;
    }

    /**
     * @inheritDoc
     */
    public function findAll(?CriteriaInterface $criteria = null): CollectionInterface
    {
        $query = $this->query();

        if ($criteria !== null) {
            $query->matching($criteria);
        }

        return $query->fetchAll();
    }

    /**
     * @inheritDoc
     */
    public function findOne(?CriteriaInterface $criteria = null)
    {
        if (
            $this->tryFindOneByCriteriaInEntityManagerFirst &&
            $criteria !== null &&
            null !== $entity = $this->em->getByCriteria($this, $criteria)
        ) {
            return $entity;
        }

        $query = $this->query();

        if ($criteria !== null) {
            $query->matching($criteria);
        }

        return $query->fetchOne();
    }

    /**
     * @inheritDoc
     */
    public function count(?CriteriaInterface $criteria = null): int
    {
        $query = $this->query();

        if ($criteria !== null) {
            $query->matching($criteria);
        }

        return $query->count();
    }

    /**
     * @param mixed $id
     * @return mixed|EntityInterface
     * @deprecated
     * @codeCoverageIgnore
     */
    public function getById($id)
    {
        return $this->findByPrimary($id);
    }

    /**
     * @param mixed $criteria
     * @return CollectionInterface|EntityInterface[]|mixed[]
     * @deprecated
     * @codeCoverageIgnore
     */
    public function getList($criteria)
    {
        return $this->findAll($criteria);
    }

    /**
     * @param mixed|null $primary
     * @return NotFoundException
     * @codeCoverageIgnore
     */
    protected static function makeNotFoundException($primary = null): NotFoundException
    {
        return new NotFoundException(null, compact('primary'));
    }

    /**
     * @param Result $result
     * @return RemoveException
     * @codeCoverageIgnore
     */
    protected static function makeRemoveException(Result $result): RemoveException
    {
        $e = new RuntimeException(implode('; ', $result->getErrorMessages()));
        return new RemoveException(null, [], 0, $e);
    }

    /**
     * @param Result $result
     * @return SaveException
     * @codeCoverageIgnore
     */
    protected static function makeSaveException(Result $result): SaveException
    {
        $e = new RuntimeException(implode('; ', $result->getErrorMessages()));
        return new SaveException(null, [], 0, $e);
    }

    private function splitPrimaryAndChanges(array $data, ?array $oldData): array
    {
        $primary = [];
        foreach ($this->getD7Entity()->getPrimaryArray() as $field) {
            if (null !== $oldData) {
                $primary[$field] = $oldData[$field];
            }

            unset($data[$field]);
        }
        $primary = [] === $primary ? null : $primary;

        if (null === $oldData) {
            return [$primary, $data];
        }

        $changes = [];
        foreach ($data as $offset => $value) {
            $oldValue = $oldData[$offset] ?? null;

            if ($oldValue === $value) {
                continue;
            }

            $changes[$offset] = $value;
        }

        return [$primary, $changes];
    }
}
