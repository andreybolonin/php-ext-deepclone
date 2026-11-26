<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

use Composer\InstalledVersions;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM as Doctrine;
use Doctrine\ORM\Mapping as D;
use ModernORM as Modern;
use ModernORM\Mapping as M;

#[D\Entity, D\Table(name: 'bench_parent')]
#[M\Entity, M\Table(name: 'bench_parent')]
class BenchParent
{
    #[D\Id, D\Column(type: 'integer')]
    #[M\Id, M\Column(type: 'integer')]
    public int $id;
    #[D\Column(type: 'integer')]
    #[M\Column(type: 'integer')]
    public int $score;
    #[D\Column(type: 'string')]
    #[M\Column(type: 'string')]
    public string $label;
    #[D\Column(type: 'json')]
    #[M\Column(type: 'json')]
    public array $payload;
    #[D\Column(name: 'created_at', type: 'datetime_immutable')]
    #[M\Column(name: 'created_at', type: 'datetime_immutable')]
    public DateTimeImmutable $createdAt;
    #[D\OneToMany(targetEntity: BenchChild::class, mappedBy: 'parent')]
    #[M\OneToMany(targetEntity: BenchChild::class, mappedBy: 'parent')]
    public Collection $children;
}

#[D\Entity, D\Table(name: 'bench_child')]
#[M\Entity, M\Table(name: 'bench_child')]
class BenchChild
{
    #[D\Id, D\Column(type: 'integer')]
    #[M\Id, M\Column(type: 'integer')]
    public int $id;
    #[D\Column(type: 'integer')]
    #[M\Column(type: 'integer')]
    public int $score;
    #[D\ManyToOne(targetEntity: BenchParent::class, inversedBy: 'children')]
    #[D\JoinColumn(name: 'parent_id', nullable: false)]
    #[M\ManyToOne(targetEntity: BenchParent::class, inversedBy: 'children')]
    #[M\JoinColumn(name: 'parent_id', nullable: false)]
    public BenchParent $parent;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$engine = $argv[2];
check(in_array($engine, ['doctrine', 'modernorm'], true), 'Unknown engine');
$db = DriverManager::getConnection([
    'driver' => 'pdo_pgsql',
    'host' => '127.0.0.1',
    'port' => (int) $argv[3],
    'user' => 'postgres',
    'dbname' => 'postgres',
]);

// A disposable schema gives each process its own regular, WAL-logged tables.
$schema = 'orm_bench_' . getmypid();
$db->executeStatement('CREATE SCHEMA ' . $schema);
$db->executeStatement('SET search_path TO ' . $schema);
$db->executeStatement('CREATE TABLE bench_parent (id integer PRIMARY KEY, score integer NOT NULL, label varchar(255) NOT NULL, payload json NOT NULL, created_at timestamp NOT NULL)');
$db->executeStatement('CREATE TABLE bench_child (id integer PRIMARY KEY, parent_id integer NOT NULL REFERENCES bench_parent (id), score integer NOT NULL)');

if ($engine === 'doctrine') {
    $config = new Doctrine\Configuration();
    $config->enableNativeLazyObjects(true);
    $config->setMetadataDriverImpl(new D\Driver\AttributeDriver([]));
    $em = new Doctrine\EntityManager($db, $config);
    $hydrationMode = Doctrine\Query::HYDRATE_OBJECT;
    $mapping = new Doctrine\Query\ResultSetMapping();
} else {
    $config = new Modern\Configuration();
    $config->setMetadataDriverImpl(new M\Driver\AttributeDriver([]));
    $em = new Modern\EntityManager($db, $config);
    $hydrationMode = Modern\Query::HYDRATE_OBJECT;
    $mapping = new Modern\Query\ResultSetMapping();
}

$em->getClassMetadata(BenchParent::class);
$em->getClassMetadata(BenchChild::class);
$mapping->addEntityResult(BenchParent::class, 'p');
foreach (['id' => 'id', 'score' => 'score', 'label' => 'label', 'payload' => 'payload', 'created_at' => 'createdAt'] as $column => $field) {
    $mapping->addFieldResult('p', $column, $field);
}
$joinedMapping = clone $mapping;
$joinedMapping->addJoinedEntityResult(BenchChild::class, 'c', 'p', 'children');
$joinedMapping->addFieldResult('c', 'child_id', 'id');
$joinedMapping->addFieldResult('c', 'child_score', 'score');
$joinedMapping->addMetaResult('c', 'child_parent_id', 'parent_id', false, 'integer');

$rootSql = 'SELECT p.id, p.score, p.label, p.payload, p.created_at FROM bench_parent p ORDER BY p.id';
$graphSql = 'SELECT p.id, p.score, p.label, p.payload, p.created_at, c.id AS child_id, c.score AS child_score, c.parent_id AS child_parent_id FROM bench_parent p JOIN bench_child c ON c.parent_id = p.id ORDER BY p.id, c.id';
$payload = ['tags' => ['one', 'two'], 'active' => true];
$createdAt = new DateTimeImmutable('2026-01-01 12:34:56');
$measurements = [];
$warmup = 2;
$rounds = 7;
foreach (['insert', 'update', 'delete', 'noop_flush', 'hydrate_flat', 'hydrate_joined'] as $scenario) {
    foreach ([1000, 10000] as $rows) {
        $samples = [];
        $joined = $scenario === 'hydrate_joined';
        $parents = $joined ? intdiv($rows, 10) : $rows;
        for ($round = -$warmup; $round < $rounds; ++$round) {
            $db->executeStatement('TRUNCATE bench_child, bench_parent');
            if ($scenario !== 'insert') {
                $db->executeStatement(
                    "INSERT INTO bench_parent SELECT i, i, 'benchmark-row', CAST(? AS json), CAST(? AS timestamp) FROM generate_series(1, ?) AS i",
                    [json_encode($payload, JSON_THROW_ON_ERROR), $createdAt->format('Y-m-d H:i:s'), $parents],
                );
            }
            if ($joined) {
                $db->executeStatement('INSERT INTO bench_child SELECT i, ((i - 1) / 10) + 1, i FROM generate_series(1, ?) AS i', [$rows]);
            }

            $entities = [];
            if ($scenario === 'insert') {
                for ($id = 1; $id <= $rows; ++$id) {
                    $entity = new BenchParent();
                    $entity->id = $entity->score = $id;
                    $entity->label = 'benchmark-row';
                    $entity->payload = $payload;
                    $entity->createdAt = $createdAt;
                    $entities[] = $entity;
                }
                unset($entity);
            } elseif (in_array($scenario, ['update', 'delete', 'noop_flush'], true)) {
                $entities = $em->newHydrator($hydrationMode)->hydrateAll($db->executeQuery($rootSql), $mapping);
                check(count($entities) === $rows, 'Unexpected count while preparing flush');
            }

            $operation = match ($scenario) {
                'insert' => static function () use ($em, $entities): void {
                    foreach ($entities as $entity) {
                        $em->persist($entity);
                    }
                    $em->flush();
                },
                'delete' => static function () use ($em, $entities): void {
                    foreach ($entities as $entity) {
                        $em->remove($entity);
                    }
                    $em->flush();
                },
                'update' => static function () use ($em, $entities): void {
                    foreach ($entities as $entity) {
                        $entity->score += 10;
                    }
                    $em->flush();
                },
                'noop_flush' => $em->flush(...),
                'hydrate_flat', 'hydrate_joined' => static fn () => $em->newHydrator($hydrationMode)->hydrateAll(
                    $db->executeQuery($joined ? $graphSql : $rootSql),
                    $joined ? $joinedMapping : $mapping,
                ),
            };

            gc_collect_cycles();
            memory_reset_peak_usage();
            $heap = memory_get_usage();
            $start = hrtime(true);
            $result = $operation();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $peak = memory_get_peak_usage() - $heap;

            if (is_array($result)) {
                check(count($result) === $parents, 'Incorrect number of hydrated roots');
                $last = $result[$parents - 1];
                check($last->id === $parents && $last->score === $parents && $last->payload === $payload && $last->createdAt == $createdAt, 'Incorrect hydration');
                if ($joined) {
                    check(count($last->children) === 10, 'Incorrect collection size');
                    $child = $last->children->last();
                    check($child->id === $rows && $child->score === $rows && $child->parent === $last, 'Incorrect joined hydration');
                    unset($child);
                }
                unset($last);
            } else {
                $expectedCount = $scenario === 'delete' ? 0 : $rows;
                $expectedSum = intdiv($expectedCount * ($expectedCount + 1), 2) + ($scenario === 'update' ? 10 * $rows : 0);
                [$actualCount, $actualSum] = $db->fetchNumeric('SELECT count(*), COALESCE(sum(score), 0) FROM bench_parent');
                check((int) $actualCount === $expectedCount && (int) $actualSum === $expectedSum, 'Incorrect persistence result');
            }

            if ($round >= 0) {
                $samples[] = ['ms' => $elapsed, 'peak_heap_delta_bytes' => $peak];
            }
            $em->clear();
            unset($operation, $result, $entities);
            check($em->getUnitOfWork()->size() === 0, 'Identity map remained populated after clear');
        }
        $measurements[$scenario][$rows] = $samples;
    }
}

echo json_encode([
    'engine' => $engine,
    'orm' => InstalledVersions::getPrettyVersion($engine === 'doctrine' ? 'doctrine/orm' : 'andreybolonin/modernorm'),
    'orm_reference' => InstalledVersions::getReference($engine === 'doctrine' ? 'doctrine/orm' : 'andreybolonin/modernorm'),
    'php' => PHP_VERSION,
    'dbal' => InstalledVersions::getPrettyVersion('doctrine/dbal'),
    'var_exporter' => InstalledVersions::getPrettyVersion('symfony/var-exporter'),
    'postgresql' => $db->fetchOne('SHOW server_version'),
    'fsync' => $db->fetchOne('SHOW fsync'),
    'synchronous_commit' => $db->fetchOne('SHOW synchronous_commit'),
    'os' => PHP_OS_FAMILY,
    'arch' => php_uname('m'),
    'deepclone' => phpversion('deepclone') ?: null,
    'opcache_cli' => ini_get('opcache.enable_cli'),
    'jit' => ini_get('opcache.jit'),
    'warmup' => $warmup,
    'rounds' => $rounds,
    'measurements' => $measurements,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
