<?php

declare(strict_types=1);

namespace spaceonfire\DataSource\Adapters\Bitrix\Query;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use InvalidArgumentException;
use RuntimeException;
use spaceonfire\Collection\CollectionInterface;
use spaceonfire\Collection\TypedCollection;
use spaceonfire\Criteria\Adapter\SpiralPagination\PaginableCriteria;
use spaceonfire\Criteria\CriteriaInterface;
use spaceonfire\DataSource\Adapters\Bitrix\Heap\Heap;
use spaceonfire\DataSource\EntityInterface;
use spaceonfire\DataSource\MapperInterface;
use spaceonfire\DataSource\QueryInterface;

final class D7Query implements QueryInterface
{
    /**
     * @var Query
     */
    private $query;
    /**
     * @var MapperInterface
     */
    private $mapper;
    /**
     * @var Heap
     */
    private $heap;

    /**
     * D7Query constructor.
     * @param Query $query
     * @param MapperInterface $mapper
     * @param Heap $heap
     */
    public function __construct(Query $query, MapperInterface $mapper, Heap $heap)
    {
        $query->setSelect(['*']);

        $this->query = $query;
        $this->mapper = $mapper;
        $this->heap = $heap;
    }

    /**
     * @inheritDoc
     */
    public function limit(int $limit): self
    {
        $this->query->setLimit($limit);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function offset(int $offset): self
    {
        $this->query->setOffset($offset);
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function fetchOne(): ?EntityInterface
    {
        try {
            $data = $this->query->fetch();

            return is_array($data) ? $this->createEntity($data) : null;
        } catch (SystemException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function fetchAll(): CollectionInterface
    {
        try {
            $rawItems = $this->query->fetchAll();

            $entitiesCollection = new TypedCollection([], EntityInterface::class);

            foreach ($rawItems as $item) {
                $entitiesCollection[] = $this->createEntity($item);
            }

            return $entitiesCollection;
        } catch (SystemException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function matching(CriteriaInterface $criteria): QueryInterface
    {
        if ($expression = $criteria->getWhere()) {
            $scope = (new D7QueryExpressionVisitor($this->mapper))->dispatch($expression);
            $this->query->where($scope(new ConditionTree()));
        }

        foreach ($criteria->getOrderBy() as $key => $order) {
            try {
                $this->query->addOrder(
                    $this->mapper->convertNameToStorage($key),
                    $order === SORT_ASC ? 'ASC' : 'DESC'
                );
            } catch (ArgumentException $e) {
                throw new InvalidArgumentException($e->getMessage(), $e->getCode(), $e);
            } catch (SystemException $e) {
                throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
            }
        }

        if ($criteria instanceof PaginableCriteria) {
            $criteria->getPaginator()->paginate($this);
        } else {
            if ($criteria->getOffset()) {
                $this->offset($criteria->getOffset());
            }

            if ($criteria->getLimit() !== null) {
                $this->limit($criteria->getLimit());
            }
        }

        foreach ($criteria->getInclude() as $include) {
            if (is_scalar($include) || (is_object($include) && method_exists($include, '__toString'))) {
                $include = $this->mapper->convertNameToStorage((string)$include);
                $this->query->addSelect($include, $include);
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function count(): int
    {
        try {
            $query = clone $this->query;

            $query->setSelect([new ExpressionField('COUNT', 'COUNT(1)')]);

            $result = $query->exec()->fetch();
            return (int)$result['COUNT'];
        } catch (SystemException $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode(), $e);
        }
    }

    private function createEntity(array $data): EntityInterface
    {
        [$entity, $data] = $this->mapper->init($data);
        $this->mapper->hydrate($entity, $data);

        if ($entity instanceof EntityInterface) {
            $this->heap->attach($entity, $data);
            return $entity;
        }

        throw new RuntimeException('Associated with repository class must implement ' . EntityInterface::class);
    }
}
