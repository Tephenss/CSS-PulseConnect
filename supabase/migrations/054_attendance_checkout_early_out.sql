-- Time-out + Early Out support for Event QR attendance.
-- Service-role / PHP BFF only — no anon grants.

alter table public.events
  add column if not exists early_out_enabled_at timestamptz null;

alter table public.event_sessions
  add column if not exists early_out_enabled_at timestamptz null;

alter table public.event_session_attendance
  add column if not exists check_out_at timestamptz null;

comment on column public.events.early_out_enabled_at is
  'When set, student time-out is open until early_out_enabled_at + 1 hour (then treated as off).';

comment on column public.event_sessions.early_out_enabled_at is
  'Per-seminar early out; open until early_out_enabled_at + 1 hour.';

comment on column public.event_session_attendance.check_out_at is
  'Student time-out timestamp for seminar attendance.';
