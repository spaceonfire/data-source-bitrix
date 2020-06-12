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
use spaceonfire\DataSource\Adapters\Bitrix\Heap\Heap;
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
     * @var Heap
     */
    private static $heap;

    private static function getHeap(): Heap
    {
        if (self::$heap === null) {
            self::$heap = new Heap();
        }
        return self::$heap;
    }

    /**
     * @return string|DataManager
     */
    abstract protected function getDataManager(): string;

    /**
     * @return Entity
     */
    protected function getEntity(): Entity
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
            return new D7Query($this->getDataManager()::query(), $this->getMapper(), self::getHeap());
        } catch (SystemException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function save($entity): void
    {
        $oldData = self::getHeap()->get($entity);
        $data = $this->getMapper()->extract($entity);

        $primary = $oldData !== null ? ORMTools::extractPrimary($this->getEntity(), $oldData) : null;

        ORMTools::wrapTransaction(function () use ($primary, &$data): void {
            $result = $primary
                ? $this->getDataManager()::update($primary, $data)
                : $this->getDataManager()::add($data);

            if (!$result->isSuccess()) {
                throw static::makeSaveException($result);
            }

            $data = array_merge($data, $result->getPrimary());
        });

        $data = array_merge($oldData ?? [], $data);
        self::getHeap()->attach($entity, $data);
        $this->getMapper()->hydrate($entity, $data);
    }

    /**
     * @inheritDoc
     */
    public function remove($entity): void
    {
        $oldData = self::getHeap()->get($entity);
        if ($oldData === null) {
            return;
        }

        $primary = ORMTools::extractPrimary($this->getEntity(), $oldData);

        ORMTools::wrapTransaction(function () use ($primary): void {
            $result = $this->getDataManager()::delete($primary);

            if (!$result->isSuccess()) {
                throw static::makeRemoveException($result);
            }
        });

        self::getHeap()->detach($entity);
    }

    /**
     * @inheritDoc
     */
    public function findByPrimary($primary)
    {
        $primaryKeys = array_values($this->getEntity()->getPrimaryArray());

        $primary = is_array($primary) ? $primary : [$primary];

        if (count($primaryKeys) !== count($primary)) {
            throw new InvalidArgumentException('Invalid primary');
        }

        $criteria = new Criteria();
        $expr = Criteria::expr();

        foreach ($primaryKeys as $i => $key) {
            $domainKey = $this->getMapper()->convertNameToDomain($key);

            foreach ([$domainKey, $key, $i] as $k) {
                if (isset($primary[$k])) {
                    $val = $primary[$k];
                    break;
                }
            }

            if (!isset($val)) {
                throw new InvalidArgumentException('Invalid primary');
            }

            $criteria->andWhere($expr->property($key, $expr->same($val)));
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
}
