"""Create a temporary ModernORM change tracker with opt-in dirty scans."""
from pathlib import Path
import sys

source, work = map(Path, sys.argv[1:3])
variant = work / "overlay"
coordinator = Path("Internal/UnitOfWork/ChangeTrackingCoordinator.php")
tracker = Path("Internal/UnitOfWork/LazyReferenceTracker.php")
code = (source / coordinator).read_text()
needle = """            foreach ($entitiesToProcess as $entity) {
                // Ignore uninitialized proxy objects"""
assert code.count(needle) == 1
code = code.replace(needle, """            $entitiesToProcess = $this->auditDirtyCandidates($class, $entitiesToProcess);
""" + needle)
needle = "    /**\n     * Computes the changes of an association."
assert code.count(needle) == 1
code = code.replace(needle, (Path(__file__).parent / "dirty-candidates.php.inc").read_text() + needle)
track = (source / tracker).read_text()
needle = "    public function clear(): void"
assert track.count(needle) == 1
track = track.replace(needle, """    public function auditIsEmpty(): bool
    {
        return $this->references->count() === 0;
    }

""" + needle)
for file, contents in ((coordinator, code), (tracker, track)):
    path = variant / file
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(contents)
