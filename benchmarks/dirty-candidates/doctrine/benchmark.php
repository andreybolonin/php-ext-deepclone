<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';
$mode = $GLOBALS['audit_dirty_mode'] = $argv[4];
$GLOBALS['audit_skipped'] = 0;
if ($mode !== 'stock') {
    spl_autoload_register(static function (string $class) use ($argv): void {
        if ($class === Doctrine\ORM\UnitOfWork::class) {
            require $argv[5] . '/UnitOfWork.php';
        }
    }, prepend: true);
}

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

    public function auditPreFlush(): void
    {
        if ($this->id === 1) {
            $this->score = 123;
        }
    }
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
    #[D\ManyToOne(targetEntity: BenchParent::class)]
    #[D\JoinColumn(name: 'parent_id', nullable: false)]
    #[M\ManyToOne(targetEntity: BenchParent::class)]
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
foreach (['empty', 'first-1', 'last-1', 'all-100'] as $scenario) {
    foreach ([10000] as $rows) {
        $samples = [];
        for ($round = -$warmup; $round < $rounds; ++$round) {
            $db->executeStatement('TRUNCATE bench_parent, bench_child');
            $db->executeStatement(
                "INSERT INTO bench_parent SELECT i, i, 'benchmark-row', CAST(? AS json), CAST(? AS timestamp) FROM generate_series(1, ?) AS i",
                [json_encode($payload, JSON_THROW_ON_ERROR), $createdAt->format('Y-m-d H:i:s'), $rows],
            );
            $entities = $em->newHydrator(1)->hydrateAll($db->executeQuery($rootSql), $mapping);
            check(count($entities) === $rows && $em->getUnitOfWork()->size() === $rows, 'Unexpected managed count');

            $changes = match ($scenario) {
                'first-1', 'last-1' => 100,
                'all-100' => $rows,
                default => 0,
            };
            $offset = $scenario === 'last-1' ? $rows - $changes : 0;
            for ($i = $offset; $i < $offset + $changes; ++$i) {
                $entities[$i]->score += 10;
            }
            $GLOBALS['audit_skipped'] = 0;
            gc_collect_cycles();
            memory_reset_peak_usage();
            $heap = memory_get_usage();
            $start = hrtime(true);
            $em->flush();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $peak = memory_get_peak_usage() - $heap;

            [$actualCount, $actualSum, $updatedRows] = $db->fetchNumeric(
                'SELECT count(*), sum(score), count(*) FILTER (WHERE score = id + 10 AND id > ? AND id <= ?) FROM bench_parent',
                [$offset, $offset + $changes],
            );
            check((int) $actualCount === $rows && (int) $actualSum === intdiv($rows * ($rows + 1), 2) + $changes * 10
                && (int) $updatedRows === $changes, 'Incorrect persistence result');
            $expectedSkipped = $mode === 'native-resume' ? $rows - $changes : ($mode === 'stock' ? 0 : match ($scenario) {
                'empty' => $rows,
                'last-1' => intdiv($offset, 256) * 256,
                default => 0,
            });
            check($GLOBALS['audit_skipped'] === $expectedSkipped, 'Unexpected filters used during flush');

            if ($round >= 0) {
                $samples[] = ['ms' => $elapsed, 'peak_heap_delta_bytes' => $peak, 'skipped' => $GLOBALS['audit_skipped']];
            }
            $em->clear();
            unset($entities);
            check($em->getUnitOfWork()->size() === 0, 'Identity map remained populated after clear');
        }
        $measurements[$scenario][$rows] = $samples;
    }
}


$entities = $em->newHydrator(1)->hydrateAll($db->executeQuery($rootSql), $mapping);
$record = $entities[0];
$originalDate = $record->createdAt;
$record->createdAt = clone $originalDate;
$GLOBALS['audit_skipped'] = 0;
$em->flush();
check($GLOBALS['audit_skipped'] === ($mode === 'native-resume' ? 9999 : 0), 'Distinct DateTime object bypassed the Doctrine comparator');
check($record->createdAt !== $originalDate
    && $record->createdAt->format('Y-m-d H:i:s') === $originalDate->format('Y-m-d H:i:s'), 'Date identity check failed');
check($em->getUnitOfWork()->getOriginalEntityData($record)['createdAt'] === $originalDate, 'Equivalent converted date modified the snapshot');

$em->getClassMetadata(BenchParent::class)->addLifecycleCallback('auditPreFlush', 'preFlush');
$GLOBALS['audit_skipped'] = 0;
$em->flush();
check($GLOBALS['audit_skipped'] === 0, 'Entity callback was skipped');
check((int) $db->fetchOne('SELECT score FROM bench_parent WHERE id = 1') === 123, 'Lifecycle callback change not persisted');

echo json_encode([
    'mode' => $mode,
    'checks' => ['updated row identities', 'filter counts', 'equivalent converted date', 'entity preFlush'],

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
