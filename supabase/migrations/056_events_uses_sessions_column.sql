-- CCS PulseConnect migration 056
-- Add events.uses_sessions so older app/PHP SELECT embeds stop throwing 42703.
-- Seminar detection still prefers event_mode / event_structure / event_sessions;
-- this column is a compatibility flag only (no RLS/grant changes).

alter table public.events
  add column if not exists uses_sessions boolean not null default false;

-- Backfill from existing seminar signals.
update public.events
set uses_sessions = true
where uses_sessions = false
  and (
    lower(coalesce(event_mode, '')) = 'seminar_based'
    or lower(coalesce(event_structure, '')) in ('one_seminar', 'two_seminars')
  );

comment on column public.events.uses_sessions is
  'Compatibility flag for seminar-based events. Prefer event_mode/event_structure/sessions when present.';
