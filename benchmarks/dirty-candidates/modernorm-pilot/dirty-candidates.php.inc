    /**
     * Narrow benchmark path. Objects that cannot be certified unchanged go through
     * the complete change tracker in their original order.
     */
    private function auditDirtyCandidates(ClassMetadata $class, array $entities): array
    {
        $mode = $GLOBALS['audit_dirty_mode'];
        if ($mode === 'stock' || count($entities) < 64 || $this->unitOfWork::class !== UnitOfWork::class
            || !$class->isChangeTrackingDeferredImplicit() || !$class->isIdentifierNatural()
            || $class->isReadOnly || $class->versionField !== null || $class->embeddedClasses !== []
            || !$class->isInheritanceTypeNone() || $class->reflClass?->getParentClass() !== false
            || $this->flushQueues->insertions() !== [] || $this->flushQueues->deletions() !== []
            || !$this->lazyReferenceTracker->auditIsEmpty()
            || ($this->listenersInvoker->getSubscribedSystems($class, Events::preFlush) & ~ListenersInvoker::INVOKE_MANAGER) !== 0
        ) {
            return $entities;
        }

        $fields = array_keys($class->fieldMappings);
        $association = null;
        if ($class->associationMappings !== []) {
            if (count($class->associationMappings) !== 1) {
                return $entities;
            }
            $mapping = reset($class->associationMappings);
            if (!$mapping->isOneToMany()) {
                return $entities;
            }
            $association = $mapping->fieldName;
        }

        foreach ($class->propertyAccessors as $field => $accessor) {
            if ($accessor::class !== \ModernORM\Mapping\PropertyAccessors\RawValuePropertyAccessor::class
                && ($accessor::class !== \ModernORM\Mapping\PropertyAccessors\TypedNoDefaultPropertyAccessor::class || !$accessor->usesRawPropertyAccess())
            ) {
                return $entities;
            }
            $property = $accessor->getUnderlyingReflector();
            if (!$property->isPublic() || $property->getName() !== $field || $property->hasHooks()
                || ($field !== $association && (!isset($class->fieldMappings[$field]) || $class->fieldMappings[$field]->enumType !== null))
            ) {
                return $entities;
            }
        }

        if ($mode === 'native') {
            $collectionPlan = $association === null ? null : [
                $association, PersistentCollection::class, 'owner', 'isDirty', 'collection', ArrayCollection::class, 'elements',
            ];
            $candidates = deepclone_dirty_candidates($class->name, $fields, $entities, $this->entityStateManager->snapshots, $collectionPlan);
        } else {
            $candidates = $this->auditPhpDirtyCandidates($class, $entities, $fields, $association);
        }
        $GLOBALS['audit_dirty_skipped'] += count($entities) - count($candidates);

        return $candidates;
    }

    private function auditPhpDirtyCandidates(ClassMetadata $class, array $entities, array $fields, ?string $association): array
    {
        $candidates = [];
        foreach ($entities as $entity) {
            $snapshot = $this->entityStateManager->snapshots[spl_object_id($entity)] ?? null;
            if ($entity::class !== $class->name || $snapshot === null || $class->reflClass->isUninitializedLazyObject($entity)) {
                $candidates[] = $entity;
                continue;
            }
            $data = (array) $entity;
            foreach ($fields as $field) {
                if (!isset($data[$field], $snapshot[$field]) || $data[$field] !== $snapshot[$field]) {
                    $candidates[] = $entity;
                    continue 2;
                }
            }
            if ($association !== null) {
                $collection = $data[$association] ?? null;
                if (!$collection instanceof PersistentCollection || ($snapshot[$association] ?? null) !== $collection
                    || $collection->getOwner() !== $entity || $collection->isDirty()
                    || $collection->unwrap()::class !== ArrayCollection::class || !$collection->unwrap()->isEmpty()
                ) {
                    $candidates[] = $entity;
                }
            }
        }

        return $candidates;
    }
