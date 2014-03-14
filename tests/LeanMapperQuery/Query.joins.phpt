<?php

/**
 * Test: LeanMapperQuery\Query automatic joins.
 * @author Michal Bohuslávek
 */

use LeanMapper\Entity;
use Tester\Assert;

require_once __DIR__ . '/../bootstrap.php';

/**
 * @property int $id
 * @property string $name
 */
class Tag extends Entity
{
}

/**
 * @property int $id
 * @property Author $author m:hasOne
 * @property Author|NULL $reviewer m:hasOne(reviewer_id)
 * @property Tag[] $tags m:hasMany
 * @property string $pubdate
 * @property string $name
 * @property string|NULL $description
 * @property string|NULL $website
 * @property bool $available
 */
class Book extends Entity
{
}

/**
 * @property int $id
 * @property string $name
 * @property Book[] $books m:belongsToMany
 * @property Book[] $reviewedBooks m:belongsToMany(reviewer_id)
 * @property string|NULL $web
 */
class Author extends Entity
{
}


//////// Basic joins ////////

// HasOne relationship
$fluent = getFluent('book');
$query = getQuery();
$query->where('@author.name', 'Karel')
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->leftJoin('author')->on('[book].[author_id] = [author].[id]')
	->where("([author].[name] = 'Karel')");
Assert::same((string) $expected, (string) $fluent);

// BelongsTo relationship
$fluent = getFluent('author');
getQuery()
	->where('@books.available', TRUE)
	->applyQuery($fluent, $mapper);

$expected = getFluent('author')
	->leftJoin('book')->on('[author].[id] = [book].[author_id]')
	->where('([book].[available] = 1)');
Assert::same((string) $expected, (string) $fluent);

// HasMany relationship
$fluent = getFluent('book');
getQuery()
	->where('@tags.name <>', 'php')
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->leftJoin('book_tag')->on('[book].[id] = [book_tag].[book_id]')
	->leftJoin('tag')->on('[book_tag].[tag_id] = [tag].[id]')
	->where("([tag].[name] <> 'php')");
Assert::same((string) $expected, (string) $fluent);


//////// Multiple join of the same table ////////

$fluent = getFluent('book');
$query->where('@reviewer.web', 'http://leanmapper.com')
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->leftJoin('author')->on('[book].[author_id] = [author].[id]')
	->leftJoin('[author] [author_reviewer_id]')->on('[book].[reviewer_id] = [author_reviewer_id].[id]')
	->where("([author].[name] = 'Karel')")
	->where("([author_reviewer_id].[web] = 'http://leanmapper.com')");
Assert::same((string) $expected, (string) $fluent);


//////// Optional specifying of primary key ////////

// HasOne
$fluent = getFluent('book');
getQuery()
	->where('@author', 2)
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->leftJoin('author')->on('[book].[author_id] = [author].[id]')
	->where('([author].[id] = 2)');
Assert::same((string) $expected, (string) $fluent);

// HasMany
$fluent = getFluent('book');
getQuery()
	->where('@tags', 2)
	->applyQuery($fluent, $mapper);

$expected = getFluent('book')
	->leftJoin('book_tag')->on('[book].[id] = [book_tag].[book_id]')
	->leftJoin('tag')->on('[book_tag].[tag_id] = [tag].[id]')
	->where('([tag].[id] = 2)');
Assert::same((string) $expected, (string) $fluent);

// BelongsTo
$fluent = getFluent('author');
getQuery()
	->where('@books', 2)
	->applyQuery($fluent, $mapper);

$expected = getFluent('author')
	->leftJoin('book')->on('[author].[id] = [book].[author_id]')
	->where('([book].[id] = 2)');
Assert::same((string) $expected, (string) $fluent);