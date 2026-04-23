-- CCS PulseConnect migration 012
-- Allow 'absent' as a valid attendance status for both simple and seminar flows.

do $$
begin
  if exists (
    select 1
    from pg_constraint
    where conname = 'attendance_status_check'
      and conrelid = 'public.attendance'::regclass
  ) then
    alter table public.attendance
      drop constraint attendance_status_check;
  end if;

  alter table public.attendance
    add constraint attendance_status_check
    check (status in ('unscanned','present','scanned','late','early','invalid','absent'));
exception
  when undefined_table then
    null;
end $$;

do $$
begin
  if exists (
    select 1
    from pg_constraint
    where conname = 'event_session_attendance_status_check'
      and conrelid = 'public.event_session_attendance'::regclass
  ) then
    alter table public.event_session_attendance
      drop constraint event_session_attendance_status_check;
  end if;

  alter table public.event_session_attendance
    add constraint event_session_attendance_status_check
    check (status in ('unscanned','present','scanned','late','early','invalid','absent'));
exception
  when undefined_table then
    null;
end $$;

-- Refresh PostgREST schema cache.
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
