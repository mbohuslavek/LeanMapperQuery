<?php

/**
 * This file is part of the LeanMapperQuery extension
 * for the Lean Mapper library (http://leanmapper.com)
 * Copyright (c) 2013 Michal Bohuslávek
 */

namespace LeanMapperQuery;

use LeanMapper;
use LeanMapper\Filtering;
use LeanMapper\Fluent;
use LeanMapper\Reflection\Property;
use LeanMapper\Relationship;
use LeanMapper\Result;
use LeanMapperQuery\Caller;
use LeanMapperQuery\Exception\InvalidArgumentException;
use LeanMapperQuery\Exception\InvalidMethodCallException;
use LeanMapperQuery\Exception\InvalidRelationshipException;
use LeanMapperQuery\Exception\InvalidStateException;
use LeanMapperQuery\Exception\InvalidStrategyException;
use LeanMapperQuery\Exception\MemberAccessException;
use LeanMapperQuery\IQuery;

/**
 * @author Michal Bohuslávek
 */
class Entity extends LeanMapper\Entity
{
	/** @var array */
	protected static $magicMethodsPrefixes = [];

	protected function queryProperty($field, IQuery $query)
	{
		if ($this->isDetached()) {
			throw new InvalidStateException('Cannot query detached entity.');
		}
		$property = $this->getCurrentReflection()->getEntityProperty($field);
		if ($property === NULL) {
			throw new MemberAccessException("Cannot access undefined property '$field' in entity " . get_called_class() . '.');
		}
		if (!$property->hasRelationship()) {
			throw new InvalidArgumentException("Property '{$property->getName()}' in entity ". get_called_class() . " has no relationship.");
		}
		$class = $property->getType();
		$filters = $this->createImplicitFilters($class, new Caller($this, $property))->getFilters();
		$mapper = $this->mapper;
		$filters[] = function (Fluent $fluent) use ($mapper, $query) {
			$query->applyQuery($fluent, $mapper);
		};

		$relationship = $property->getRelationship();
		if ($relationship instanceof Relationship\BelongsToMany) {
			$targetTable = $relationship->getTargetTable();
			$referencingColumn = $relationship->getColumnReferencingSourceTable();
			$strategy = $relationship->getStrategy();
			$detectStrategy = $strategy !== Result::STRATEGY_UNION;

			if ($detectStrategy) {
				$filters[] = function (Fluent $fluent) {
					if ($fluent->_export('LIMIT') || $fluent->_export('OFFSET')) {
						throw new InvalidStrategyException('Fluent uses LIMIT or OFFSET, use UNION strategy.');
					}
				};
			}

			$rows = [];

			try {
				$rows = $this->row->referencing($targetTable, $referencingColumn, new Filtering($filters), $strategy);

			} catch (InvalidStrategyException $e) {
				if ($detectStrategy) {
					array_pop($filters); // remove detector
				}
				$rows = $this->row->referencing($targetTable, $referencingColumn, new Filtering($filters), Result::STRATEGY_UNION);
			}

		} elseif ($relationship instanceof Relationship\HasMany) {
			$relationshipTable = $relationship->getRelationshipTable();
			$sourceReferencingColumn = $relationship->getColumnReferencingSourceTable();
			$targetReferencingColumn = $relationship->getColumnReferencingTargetTable();
			$targetTable = $relationship->getTargetTable();
			$targetPrimaryKey = $mapper->getPrimaryKey($targetTable);
			$rows = [];
			$resultRows = [];
			$targetResultProxy = NULL;

			foreach ($this->row->referencing($relationshipTable, $sourceReferencingColumn) as $relationship) {
				$row = $relationship->referenced($targetTable, $targetReferencingColumn, new Filtering($filters));
				if ($row !== NULL && $targetResultProxy === NULL) {
					$targetResultProxy = $row->getResultProxy();
				}
				$row !== NULL && $resultRows[$row->{$targetPrimaryKey}] = $row;
			}

			if ($targetResultProxy) {
				foreach ($targetResultProxy as $rowId => $rowData) {
					if (isset($resultRows[$rowId])) {
						$rows[] = $resultRows[$rowId];
					}
				}
			} else {
				$rows = $resultRows;
			}

		} else {
			throw new InvalidRelationshipException('Only BelongsToMany and HasMany relationships are supported when querying entity property. ' . get_class($relationship) . ' given.');
		}
		$entities = [];
		$table = $mapper->getTable($class);
		foreach ($rows as $row) {
			$entity = $this->entityFactory->createEntity($mapper->getEntityClass($table, $row), $row);
			$entity->makeAlive($this->entityFactory);
			$entities[] = $entity;
		}
		return $entities;
	}

	public function __call($name, array $arguments)
	{
		if (preg_match('#^('.implode('|', static::$magicMethodsPrefixes).')(.+)$#', $name, $matches)) {
			if (count($arguments) !== 1) {
				throw new InvalidMethodCallException(get_called_class() . "::$name expects exactly 1 argument. " . count($arguments) . ' given.');
			}
			list($query) = $arguments;
			if (!$query instanceof IQuery) {
				throw new InvalidArgumentException('Argument 1 passed to ' . get_called_class() . "::$name must implement interface LeanMapperQuery\\IQuery. " . gettype($query) . ' given.');
			}
			list(, $method, $field) = $matches;
			$field = lcfirst($field);
			return $this->$method($field, $query);

		} else {
			return parent::__call($name, $arguments);
		}
	}

}
