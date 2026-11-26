This is an earlier ModernORM pilot. The association-specific guard was experimental and is outside the proposed API.

# Native dirty-checking experiment

The extension scans the existing per-class Identity Map bucket and original-data snapshots by object ID. It returns objects that still require ordinary dirty checking. The adapter resolves and validates field layout once per class scan. There is no per-entity PHP packing. The timer starts at EntityManager::flush() and includes the field plan and collection guard. Hydration, fixture setup and checks are outside the timer.

The supported fixtures use a single assigned ID, ordinary public properties, no scalar-value hooks and deferred implicit tracking. The filter compares arrays strictly and object-valued properties by identity, as the current tracker does for these fields. The association variant accepts a single matching, owned, clean and empty PersistentCollection backed by the standard ArrayCollection. Nonempty or dirty collections, missing snapshots and lazy objects fall back. The PHP candidate implements the same idea with an array cast and public collection methods. The collection slot descriptor is experimental and couples the C variant to current collection internals.

## Full flush results

PHP 8.5.13, PostgreSQL 18.7, Doctrine ORM 3.8.0, DBAL 4.6.0 and ModernORM `c84fb90` with the local scalar-plan guard. Ten thousand entities in each case. Four warm-up and thirteen timed rounds per case, two processes per variant in forward and reverse order. Results are medians of the process medians.

| Empty flush | Doctrine ORM | ModernORM baseline | PHP filter | Native filter |
| --- | ---: | ---: | ---: | ---: |
| Model with a clean empty OneToMany | 31.524 ms | 41.826 ms | 6.910 ms | 1.054 ms |
| Same scalar fields without the association | 18.930 ms | 27.823 ms | 2.624 ms | 0.638 ms |

Each timed candidate run asserted that its filter certified all 10,000 entities unchanged. The integration fixture also checks actual SQL updates for changed scalar and JSON fields, snapshot identity after replacing a DateTimeImmutable, and fallback for dirty and nonempty collections. The direct extension probe checks nested array changes, replacement date identity, untouched lazy objects, missing snapshots and rejection of hooked properties. The experiment is limited to empty flush timings and leaves changed-heavy workload costs and broader eligibility unmeasured.

## Reproduce

Apply the adjacent [batch prototype patch](deepclone-hydrate-batch.patch) to php-ext-deepclone at the revision specified there. Run `prepare-extension.py` with that checkout directory, regenerate arginfo using PHP's `gen_stub.php`, then build the module using phpize and configure. Run `probe.php` with the module loaded.

Install the [Doctrine comparison Composer lockfile](../doctrine/composer.lock) in a temporary directory. Run `prepare.py /path/to/modernorm/src /path/to/temporary-directory` and `prepare-benchmark.py /path/to/temporary-directory`. Start a separate local PostgreSQL instance. Run the generated `associated.php` or `scalar.php` with the module loaded, passing the temporary directory, engine (`modernorm` or `doctrine`), PostgreSQL port, mode (`stock`, `php` or `native`), and ModernORM source path. Use `stock` for Doctrine and CLI OPcache enabled with JIT disabled.
