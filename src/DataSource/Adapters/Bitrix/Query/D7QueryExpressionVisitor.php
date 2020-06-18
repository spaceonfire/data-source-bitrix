<?php

declare(strict_types=1);

namespace spaceonfire\DataSource\Adapters\Bitrix\Query;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use spaceonfire\DataSource\Query\AbstractExpressionVisitor;
use Webmozart\Expression\Constraint;
use Webmozart\Expression\Expression;
use Webmozart\Expression\Logic;

class D7QueryExpressionVisitor extends AbstractExpressionVisitor
{
    /**
     * @inheritDoc
     */
    public function visitConjunction(Logic\AndX $expression): callable
    {
        return function (ConditionTree $filter) use ($expression) {
            $filter->logic(ConditionTree::LOGIC_AND);

            foreach ($expression->getConjuncts() as $conjunct) {
                $this->dispatch($conjunct)($filter);
            }

            return $filter;
        };
    }

    /**
     * @inheritDoc
     */
    public function visitDisjunction(Logic\OrX $expression): callable
    {
        return function (ConditionTree $filter) use ($expression) {
            $filter->logic(ConditionTree::LOGIC_OR);

            foreach ($expression->getDisjuncts() as $disjuncts) {
                $this->dispatch($disjuncts)($filter);
            }

            return $filter;
        };
    }

    /**
     * @inheritDoc
     */
    public function visitComparison(string $field, Expression $expression, bool $isNegated = false): callable
    {
        $supportedNegateExpressions = [
            Constraint\Contains::class,
            Constraint\EndsWith::class,
            Constraint\StartsWith::class,
            Constraint\In::class,
            Constraint\Equals::class,
            Constraint\Same::class,
            Constraint\GreaterThan::class,
            Constraint\GreaterThanEqual::class,
            Constraint\LessThan::class,
            Constraint\LessThanEqual::class,
            null,
        ];

        if ($isNegated) {
            /** @var string|Expression|null $expressionClass */
            foreach ($supportedNegateExpressions as $expressionClass) {
                if ($expressionClass !== null && $expression instanceof $expressionClass) {
                    $expression = $this->negateExpression($expression);
                    break;
                }
            }

            if ($expressionClass === null) {
                throw $this->makeNotSupportedExpression($expression);
            }
        }

        switch (true) {
            case $expression instanceof Constraint\Equals:
            case $expression instanceof Constraint\Same:
                return function (ConditionTree $filter) use ($field, $expression) {
                    $val = $this->visitValue($field, $expression->getComparedValue());

                    if ($val === null) {
                        $filter->whereNull($field);
                    } else {
                        $filter->where($field, $val);
                    }

                    return $filter;
                };
            // no break

            case $expression instanceof Constraint\NotEquals:
            case $expression instanceof Constraint\NotSame:
                return function (ConditionTree $filter) use ($field, $expression) {
                    $val = $this->visitValue($field, $expression->getComparedValue());

                    if ($val === null) {
                        $filter->whereNotNull($field);
                    } else {
                        $filter->where($field, '<>', $val);
                    }

                    return $filter;
                };
            // no break

            case $expression instanceof Constraint\In:
                return function (ConditionTree $filter) use ($field, $expression, $isNegated) {
                    $val = array_map(function ($v) use ($field) {
                        return $this->visitValue($field, $v);
                    }, $expression->getAcceptedValues());

                    if ($isNegated) {
                        $filter->whereNotIn($field, $val);
                    } else {
                        $filter->whereIn($field, $val);
                    }

                    return $filter;
                };
            // no break

            case $expression instanceof Constraint\Contains:
                return function (ConditionTree $filter) use ($field, $expression, $isNegated) {
                    $val = $this->visitValue($field, $expression->getComparedValue());

                    if ($isNegated) {
                        $filter->whereNotLike($field, '%' . $val . '%');
                    } else {
                        $filter->whereLike($field, '%' . $val . '%');
                    }

                    return $filter;
                };
            // no break

            case $expression instanceof Constraint\StartsWith:
                return function (ConditionTree $filter) use ($field, $expression, $isNegated) {
                    $val = $this->visitValue($field, $expression->getAcceptedPrefix());

                    if ($isNegated) {
                        $filter->whereNotLike($field, $val . '%');
                    } else {
                        $filter->whereLike($field, $val . '%');
                    }

                    return $filter;
                };
            // no break

            case $expression instanceof Constraint\EndsWith:
                return function (ConditionTree $filter) use ($field, $expression, $isNegated) {
                    $val = $this->visitValue($field, $expression->getAcceptedSuffix());

                    if ($isNegated) {
                        $filter->whereNotLike($field, '%' . $val);
                    } else {
                        $filter->whereLike($field, '%' . $val);
                    }

                    return $filter;
                };
            // no break

            case $expression instanceof Constraint\GreaterThan:
            case $expression instanceof Constraint\GreaterThanEqual:
            case $expression instanceof Constraint\LessThan:
            case $expression instanceof Constraint\LessThanEqual:
                return function (ConditionTree $filter) use ($field, $expression) {
                    $val = $this->visitValue($field, $expression->getComparedValue());

                    $operatorsMap = [
                        Constraint\GreaterThan::class => '>',
                        Constraint\GreaterThanEqual::class => '>=',
                        Constraint\LessThan::class => '<',
                        Constraint\LessThanEqual::class => '<=',
                    ];

                    /**
                     * @var string|Expression $expressionClass
                     * @var string $operator
                     */
                    foreach ($operatorsMap as $expressionClass => $operator) {
                        if ($expression instanceof $expressionClass) {
                            break;
                        }
                    }

                    $filter->where($field, $operator, $val);

                    return $filter;
                };
            // no break
        }

        throw $this->makeNotSupportedExpression($expression);
    }
}
