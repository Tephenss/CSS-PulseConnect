-- Per-session participant targeting and seminar attendance completion policy.

alter table public.event_sessions
  add column if not exists target_participant text not null default '';

comment on column public.event_sessions.target_participant is
  'Optional COURSE=...;YEARS=... target. Empty means inherit the parent event target.';

alter table public.events
  add column if not exists session_attendance_rule text not null default 'all';

do $$
begin
  if not exists (
    select 1
    from pg_constraint
    where conname = 'events_session_attendance_rule_check'
      and conrelid = 'public.events'::regclass
  ) then
    alter table public.events
      add constraint events_session_attendance_rule_check
      check (session_attendance_rule in ('all', 'any'));
  end if;
end $$;

comment on column public.events.session_attendance_rule is
  'For seminar-based events: all matching sessions must be attended, or any one matching session is sufficient.';
