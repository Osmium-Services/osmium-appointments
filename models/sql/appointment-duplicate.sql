SELECT id FROM {TABLE}
WHERE customer_email = :email AND starts_at = :starts_at AND type_id = :type_id
  AND status IN ('requested', 'confirmed') AND deleted_at IS NULL
LIMIT 1
