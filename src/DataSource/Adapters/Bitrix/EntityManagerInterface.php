<?php

namespace spaceonfire\DataSource\Adapters\Bitrix;

use spaceonfire\Criteria\CriteriaInterface;
use spaceonfire\DataSource\EntityInterface;
use spaceonfire\DataSource\MapperInterface;
use spaceonfire\DataSource\RepositoryInterface;

interface EntityManagerInterface
{
    /**
     * Resolve entity role
     * @param string|mixed $role
     * @return string
     */
    public function resolveRole($role): string;

    /**
     * Register repository
     * @param RepositoryInterface $repository
     * @param string|null $role
     */
    public function registerRepository(RepositoryInterface $repository, ?string $role = null): void;

    /**
     * Attach entity
     * @param EntityInterface $entity
     * @param array $data
     */
    public function attachEntity(EntityInterface $entity, array $data): void;

    /**
     * Detach entity
     * @param EntityInterface $entity
     */
    public function detachEntity(EntityInterface $entity): void;

    /**
     * Returns data by given entity
     * @param EntityInterface $entity
     * @return array|null
     */
    public function getEntityData(EntityInterface $entity): ?array;

    /**
     * Returns repository by role
     * @param string|mixed $role
     * @return RepositoryInterface|AbstractD7Repository
     */
    public function getRepository($role): RepositoryInterface;

    /**
     * Returns mapper by role
     * @param string|mixed $role
     * @return MapperInterface|AbstractMapper
     */
    public function getMapper($role): MapperInterface;

    /**
     * Returns found entity by index
     * @param string|mixed $role
     * @param array $data
     * @return EntityInterface|null
     */
    public function getByIndex($role, array $data): ?EntityInterface;

    /**
     * Returns found entity by index or creates new with given data
     * @param string|mixed $role
     * @param array $data
     * @return EntityInterface
     */
    public function make($role, array $data): EntityInterface;

    /**
     * Returns found entity by criteria's expression
     * @param string|mixed $role
     * @param CriteriaInterface $criteria
     * @return EntityInterface|null
     */
    public function getByCriteria($role, CriteriaInterface $criteria): ?EntityInterface;
}
