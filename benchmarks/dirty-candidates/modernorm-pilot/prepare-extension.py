from pathlib import Path
import sys

root = Path(sys.argv[1])
p = root / "deepclone.c"
source = p.read_text()
marker = "/* ── Module boilerplate"
assert source.count(marker) == 1
assert "PHP_FUNCTION(deepclone_dirty_candidates)" not in source
code = (Path(__file__).parent / "dirty-scan.c").read_text()
p.write_text(source.replace(marker, code + "\n" + marker, 1))

p = root / "deepclone.stub.php"
source = p.read_text()
marker = "    function deepclone_hydrate_batch"
assert source.count(marker) == 1
p.write_text(source.replace(marker, "    function deepclone_dirty_candidates(string $class, array $fields, array $objects, array $snapshots, ?array $collection = null): array {}\n\n" + marker, 1))
