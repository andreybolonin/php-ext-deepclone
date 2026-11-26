typedef struct {
	zend_string *field;
	uint32_t offset;
} dc_dirty_field;

typedef struct {
	zend_class_entry *collection_ce, *storage_ce;
	uint32_t field_offset, owner_offset, dirty_offset, storage_offset, elements_offset;
} dc_dirty_collection;

static bool dc_dirty_offset(zend_class_entry *ce, zend_string *key, uint32_t *offset)
{
	zend_string *name;
	bool mangled;
	zend_class_entry *scope = dc_resolve_hydration_key(ce, key, &name, &mangled,
		"deepclone_dirty_candidates(): property plan");
	if (!scope) {
		return false;
	}
	zend_property_info *info = zend_hash_find_ptr(&scope->properties_info, name);
	if (!dc_is_backed_declared_property(info) || DC_PROP_HAS_HOOKS(info)) {
		zend_value_error("deepclone_dirty_candidates(): expected a backed instance property without hooks");
		return false;
	}
	*offset = info->offset;
	return true;
}

/*
 * Return objects that need normal change tracking. The caller supplies a class
 * bucket and the existing UnitOfWork snapshots indexed by object ID.
 */
PHP_FUNCTION(deepclone_dirty_candidates)
{
	zend_string *class_name;
	HashTable *fields, *objects, *snapshots, *collection = NULL;
	ZEND_PARSE_PARAMETERS_START(4, 5)
		Z_PARAM_STR(class_name)
		Z_PARAM_ARRAY_HT(fields)
		Z_PARAM_ARRAY_HT(objects)
		Z_PARAM_ARRAY_HT(snapshots)
		Z_PARAM_OPTIONAL
		Z_PARAM_ARRAY_HT_OR_NULL(collection)
	ZEND_PARSE_PARAMETERS_END();

	zend_class_entry *ce = zend_lookup_class(class_name);
	if (!ce) {
		if (!EG(exception)) {
			zend_value_error("deepclone_dirty_candidates(): class not found");
		}
		RETURN_THROWS();
	}

	dc_dirty_collection guard = {0};
	if (collection) {
		zval *parts[7];
		for (uint32_t i = 0; i < 7; ++i) {
			parts[i] = zend_hash_index_find(collection, i);
			if (!parts[i] || Z_TYPE_P(parts[i]) != IS_STRING) {
				zend_value_error("deepclone_dirty_candidates(): invalid collection plan");
				RETURN_THROWS();
			}
		}
		guard.collection_ce = zend_lookup_class(Z_STR_P(parts[1]));
		if (EG(exception)) {
			RETURN_THROWS();
		}
		guard.storage_ce = zend_lookup_class(Z_STR_P(parts[5]));
		if (EG(exception)) {
			RETURN_THROWS();
		}
		if (!guard.collection_ce || !guard.storage_ce) {
			zend_value_error("deepclone_dirty_candidates(): collection or storage class not found");
			RETURN_THROWS();
		}
		if (!dc_dirty_offset(ce, Z_STR_P(parts[0]), &guard.field_offset)
			|| !dc_dirty_offset(guard.collection_ce, Z_STR_P(parts[2]), &guard.owner_offset)
			|| !dc_dirty_offset(guard.collection_ce, Z_STR_P(parts[3]), &guard.dirty_offset)
			|| !dc_dirty_offset(guard.collection_ce, Z_STR_P(parts[4]), &guard.storage_offset)
			|| !dc_dirty_offset(guard.storage_ce, Z_STR_P(parts[6]), &guard.elements_offset)) {
			RETURN_THROWS();
		}
	}

	uint32_t count = zend_hash_num_elements(fields);
	dc_dirty_field *plan = count ? safe_emalloc(count, sizeof(dc_dirty_field), 0) : NULL;
	uint32_t column = 0;
	zval *field;
	ZEND_HASH_FOREACH_VAL(fields, field) {
		if (Z_TYPE_P(field) != IS_STRING) {
			zend_value_error("deepclone_dirty_candidates(): expected string field names");
			if (plan) efree(plan);
			RETURN_THROWS();
		}
		plan[column].field = Z_STR_P(field);
		if (!dc_dirty_offset(ce, Z_STR_P(field), &plan[column].offset)) {
			if (plan) efree(plan);
			RETURN_THROWS();
		}
		++column;
	} ZEND_HASH_FOREACH_END();

	array_init(return_value);
	zval *value;
	ZEND_HASH_FOREACH_VAL(objects, value) {
		bool unchanged = false;
		if (Z_TYPE_P(value) != IS_OBJECT || Z_OBJCE_P(value) != ce) {
			goto candidate;
		}
		zend_object *obj = Z_OBJ_P(value);
#if PHP_VERSION_ID >= 80400
		if (zend_object_is_lazy(obj)) {
			goto candidate;
		}
#endif
		zval *snapshot = zend_hash_index_find(snapshots, obj->handle);
		if (!snapshot || Z_TYPE_P(snapshot) != IS_ARRAY) {
			goto candidate;
		}

		for (uint32_t i = 0; i < count; ++i) {
			zval *before = zend_hash_find(Z_ARRVAL_P(snapshot), plan[i].field);
			zval *after = OBJ_PROP(obj, plan[i].offset);
			if (!before || Z_TYPE_P(after) <= IS_NULL || Z_ISREF_P(after) || Z_ISREF_P(before)
				|| !fast_is_identical_function(before, after)) {
				goto candidate;
			}
		}

		if (collection) {
			zval *current = OBJ_PROP(obj, guard.field_offset);
			zval *before = zend_hash_find(Z_ARRVAL_P(snapshot), Z_STR_P(zend_hash_index_find(collection, 0)));
			if (!before || Z_TYPE_P(before) != IS_OBJECT || Z_TYPE_P(current) != IS_OBJECT
				|| Z_OBJ_P(before) != Z_OBJ_P(current) || Z_OBJCE_P(current) != guard.collection_ce) {
				goto candidate;
			}
			zend_object *coll = Z_OBJ_P(current);
			zval *owner = OBJ_PROP(coll, guard.owner_offset);
			if (Z_TYPE_P(owner) != IS_OBJECT || Z_OBJ_P(owner) != obj
				|| Z_TYPE_P(OBJ_PROP(coll, guard.dirty_offset)) != IS_FALSE) {
				goto candidate;
			}
			zval *storage = OBJ_PROP(coll, guard.storage_offset);
			if (Z_TYPE_P(storage) != IS_OBJECT || Z_OBJCE_P(storage) != guard.storage_ce) {
				goto candidate;
			}
			zval *elements = OBJ_PROP(Z_OBJ_P(storage), guard.elements_offset);
			if (Z_TYPE_P(elements) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(elements)) != 0) {
				goto candidate;
			}
		}

		unchanged = true;
candidate:
		if (!unchanged) {
			Z_TRY_ADDREF_P(value);
			add_next_index_zval(return_value, value);
		}
	} ZEND_HASH_FOREACH_END();
	if (plan) efree(plan);
}
