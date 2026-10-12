# ADR-002 — Single writer

Accepted design: aggregate authority epoch + CAS, native outbox atomic; legacy best-effort capture/reconciliation + cutover barrier. See 02 §4/03 §5/08 §6/08 §7. No last-write-wins two-source merge.
