# Native dirty candidates benchmark evidence

The final Doctrine rerun used the proposed module at `e5ee2f4eea5c43b420f519731d708a099d94f6ef`. Doctrine ORM 3.8.0, DBAL 4.6.0, PHP 8.5.13 and PostgreSQL 18.7 ran on Apple Silicon with CLI OPcache enabled and JIT disabled. The comparison used 10,000 managed entities with an assigned integer key, strings, JSON and an immutable date. It measures the full flush including planning, chunks and SQL. Hydration, mutation and validation are outside the timer.

Each variant ran in two independent PHP processes in forward/reverse order with two warm-up and seven timed rounds per process. Times are the midpoint of the two process medians.

| Full Doctrine flush | Stock | PHP filter | C filter | C filter with continued scanning |
| --- | ---: | ---: | ---: | ---: |
| Empty | 18.838 ms | 2.697 ms | 0.664 ms | 0.796 ms |
| First 1% changed | 28.450 ms | 28.365 ms | 28.769 ms | 10.514 ms |
| Last 1% changed | 28.521 ms | 11.996 ms | 10.411 ms | 10.295 ms |
| All changed | 518.703 ms | 518.687 ms | 520.485 ms | 518.579 ms |

Stock loads the Composer UnitOfWork verbatim. The two filters use the same adapter, batch size of 256 and original snapshots. They stop filtering on the first uncertain chunk. Applying the narrow C API instead of the equivalent PHP filter saved 2.033 ms in the empty case and 1.585 ms in the last-1% case. Differences were small when the first chunk changed or all entities changed.

The continued-scanning mode is a separate integration experiment for this flat fixture, using the same C API. It keeps scanning and sends candidates through the usual changeset calculation in order. It skipped the 9,900 unchanged entities even when the first 1% changed. Applying this strategy to other mappings requires proving that processing a candidate has no effect on objects previously certified unchanged. The mode saved no meaningful time when all objects changed.

Each run validates the number and identities of affected SQL rows, filter counts, retention of Doctrine's comparison of equivalent converted DateTime values, and preservation of entity preFlush behavior. The API rejects hooked property plans and leaves lazy objects untouched. See the [local PHPT results](PHPT-results.txt).

## Reproduce the Doctrine run

1. Install the committed [Composer lockfile](doctrine/composer.lock) in a temporary directory. Start a disposable local PostgreSQL instance with a database and role named `postgres`.
2. Copy `vendor/doctrine/orm/src/UnitOfWork.php` from the installed project into a separate work directory. Apply [the benchmark-only patch](doctrine/UnitOfWork.patch) with `patch -p0` from that directory. Only the experiment autoloader uses this file.
3. Compile the proposed extension and run [benchmark.php](doctrine/benchmark.php) via `php -n -d opcache.enable_cli=1 -d opcache.jit=disable -d extension=/path/to/deepclone.so benchmark.php <composer-directory> doctrine <port> <mode> <work-directory>`. Use modes `stock`, `php`, `native` and `native-resume`. The [Doctrine directory](doctrine/) contains all eight raw runs.

## ModernORM pilot

The [earlier ModernORM pilot](modernorm-pilot/) includes its sources and raw runs. On 10,000 unchanged entities without associations, the full flush took 27.823 ms on stock ModernORM, 2.624 ms with the PHP filter and 0.638 ms with the native pilot. It also includes an exploratory collection-specific guard. Results from that guard are not attributed to this upstream API.
