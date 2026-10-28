INSERT INTO {TABLE}
    (reference, type_id, resource_id, customer_name, customer_email, customer_phone, customer_notes, starts_at, ends_at, status)
VALUES
    (:reference, :type_id, :resource_id, :customer_name, :customer_email, :customer_phone, :customer_notes, :starts_at, :ends_at, 'requested')
