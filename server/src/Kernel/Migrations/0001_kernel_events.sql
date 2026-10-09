-- The outbox of domain events (design section 2.1 rule 5). An event is
-- written in the same transaction as the change that caused it and delivered
-- later by the tick. type is "<context>.<name>"; payload is JSON with IDs and
-- values only. delivered_at is NULL while pending. An event whose delivery
-- failed on 3 ticks stays pending with failed_attempts 3 and is skipped.
CREATE TABLE kernel_events (
    id INTEGER PRIMARY KEY,
    type TEXT NOT NULL,
    payload TEXT NOT NULL,
    occurred_at INTEGER NOT NULL,
    delivered_at INTEGER,
    failed_attempts INTEGER NOT NULL DEFAULT 0
);

-- Pending events (delivered_at NULL) in id order, and pruning by delivery time.
CREATE INDEX kernel_events_delivery ON kernel_events (delivered_at, id);
