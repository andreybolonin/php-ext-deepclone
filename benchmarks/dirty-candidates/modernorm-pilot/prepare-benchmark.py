from pathlib import Path
import re
import sys

work = Path(sys.argv[1])
fixture = Path(__file__).resolve().parent.parent / "doctrine-comparison/benchmark.php"
code = fixture.read_text().replace("require $argv[1] . '/vendor/autoload.php';", """require $argv[1] . '/vendor/autoload.php';
$GLOBALS['audit_dirty_mode'] = $argv[4];
$GLOBALS['audit_dirty_skipped'] = 0;
$src = $argv[5];
if ($argv[2] === 'modernorm') {
    spl_autoload_register(static function (string $class) use ($argv, $src): void {
        if (str_starts_with($class, 'ModernORM\\\\')) {
            $path = str_replace('\\\\', '/', substr($class, strlen('ModernORM\\\\'))) . '.php';
            $overlay = $argv[1] . '/overlay/' . $path;
            if (is_file($overlay)) {
                require $overlay;
            } elseif (is_file($src . '/' . $path)) {
                require $src . '/' . $path;
            }
        }
    }, prepend: true);
}""")
code = code.replace("'insert', 'update', 'delete', 'noop_flush', 'hydrate_flat', 'hydrate_joined'", "'noop_flush'")
code = code.replace("foreach ([1000, 10000] as $rows)", "foreach ([10000] as $rows)")
code = code.replace("$warmup = 2;", "$warmup = 4;").replace("$rounds = 7;", "$rounds = 13;")
code = code.replace("            $start = hrtime(true);", "            $GLOBALS['audit_dirty_skipped'] = 0;\n            $start = hrtime(true);")
code = code.replace("            if ($round >= 0) {", """            if (in_array($GLOBALS['audit_dirty_mode'], ['native', 'php'], true)) {
                check($GLOBALS['audit_dirty_skipped'] === $rows, 'The opt-in scan did not certify the unchanged entities');
            }
            if ($round >= 0) {""")
check = r"""
if ($engine === 'modernorm') {
    $entities = $em->newHydrator($hydrationMode)->hydrateAll($db->executeQuery($rootSql), $mapping);
    $date = $entities[0]->createdAt;
    $entities[0]->createdAt = clone $date;
    $entities[0]->score = 99;
    $entities[1]->payload = ['changed' => true];
    $GLOBALS['audit_dirty_skipped'] = 0;
    $em->flush();
    check($db->fetchOne('SELECT score FROM bench_parent WHERE id = 1') === 99, 'SQL update missed scalar change');
    check(json_decode($db->fetchOne('SELECT payload FROM bench_parent WHERE id = 2'), true, flags: JSON_THROW_ON_ERROR) === ['changed' => true], 'SQL update missed JSON change');
    check($em->getUnitOfWork()->getOriginalEntityData($entities[0])['createdAt'] === $entities[0]->createdAt && $entities[0]->createdAt !== $date, 'DateTime identity not preserved');
    if ($GLOBALS['audit_dirty_mode'] !== 'stock') {
        check($GLOBALS['audit_dirty_skipped'] === count($entities) - 2, 'Unexpected count with changed entities');
        if (isset($em->getClassMetadata(BenchParent::class)->associationMappings['children'])) {
            $db->executeStatement('INSERT INTO bench_child (id, parent_id, score) VALUES (1, 3, 8)');
            check(count($entities[2]->children->toArray()) === 1, 'Expected nonempty initialized collection');
            $GLOBALS['audit_dirty_skipped'] = 0;
            $em->flush();
            check($GLOBALS['audit_dirty_skipped'] === count($entities) - 1, 'Nonempty collection did not fall back');

            $entities[3]->children->setDirty(true);
            $GLOBALS['audit_dirty_skipped'] = 0;
            $em->flush();
            check($GLOBALS['audit_dirty_skipped'] === count($entities) - 2, 'Dirty collection did not fall back');
        }
    }
}
"""
code = code.replace("echo json_encode([", check + "\necho json_encode([")
code = code.replace("    'engine' => $engine,", "    'engine' => $engine,\n    'mode' => $GLOBALS['audit_dirty_mode'],")
(work / "associated.php").write_text(code)
scalar = re.sub(r"^    #\[(?:D|M)\\OneToMany.*\n", "", code, flags=re.M)
scalar = scalar.replace(", inversedBy: 'children'", "")
(work / "scalar.php").write_text(scalar)
