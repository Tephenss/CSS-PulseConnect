-- CCS PulseConnect migration 017
-- Ensure seminar-based auto-absent can be recorded reliably.
--
-- Older schemas define `public.event_session_attendance.check_in_at` as NOT NULL
-- with default now(), which makes it impossible to represent "absent/no-scan"
-- rows cleanly. Some deployments may also still have a restrictive status check.

do $$
begin
  if to_regclass('public.event_session_attendance') is not null then
    -- Allow absent-style rows without forcing a check-in timestamp.
    begin
      execute 'alter table public.event_session_attendance alter column check_in_at drop not null';
    exception
      when others then null;
    end;

    begin
      execute 'alter table public.event_session_attendance alter column check_in_at drop default';
    exception
      when others then null;
    end;

    -- If any absent rows were previously created with a default check-in time,
    -- null them out to match the meaning of absent.
    begin
      execute $sql$
        update public.event_session_attendance
        set check_in_at = null
        where lower(coalesce(status, '')) = 'absent'
      $sql$;
    exception
      when others then null;
    end;

    -- Ensure the status constraint includes 'absent' (idempotent).
    if exists (
      select 1
      from pg_constraint
      where conname = 'event_session_attendance_status_check'
        and conrelid = 'public.event_session_attendance'::regclass
    ) then
      execute 'alter table public.event_session_attendance drop constraint event_session_attendance_status_check';
    end if;

    execute $sql$
      alter table public.event_session_attendance
        add constraint event_session_attendance_status_check
        check (status in ('unscanned','present','scanned','late','early','invalid','absent'))
    $sql$;
  end if;
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

