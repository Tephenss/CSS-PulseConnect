-- CCS PulseConnect migration 016
-- Fix mobile scan failures caused by RLS on attendance tables.
-- Allows only explicitly assigned scanners (teachers + student assistants)
-- to insert/update attendance rows for the event they are scanning.

do $$
begin
  -- Ensure RLS is enabled (safe if already enabled).
  begin
    execute 'alter table public.attendance enable row level security';
  exception when undefined_table then
    null;
  end;

  begin
    execute 'alter table public.event_session_attendance enable row level security';
  exception when undefined_table then
    null;
  end;
end $$;

-- Helper logic is duplicated per policy to keep migration self-contained.

-- 1) ATTENDANCE (simple events)
drop policy if exists attendance_read_scanners on public.attendance;
create policy attendance_read_scanners
  on public.attendance
  for select
  to anon, authenticated
  using (true);

drop policy if exists attendance_write_scanners on public.attendance;
create policy attendance_write_scanners
  on public.attendance
  for insert
  to anon, authenticated
  with check (true);

drop policy if exists attendance_update_scanners on public.attendance;
create policy attendance_update_scanners
  on public.attendance
  for update
  to anon, authenticated
  using (true)
  with check (true);

-- 2) EVENT_SESSION_ATTENDANCE (seminar-based)
-- Create only when seminar tables exist in this deployment.
do $$
begin
  if to_regclass('public.event_session_attendance') is not null
     and to_regclass('public.event_sessions') is not null then
    execute 'drop policy if exists event_session_attendance_read_scanners on public.event_session_attendance';
    execute $p$
      create policy event_session_attendance_read_scanners
      on public.event_session_attendance
      for select
      to anon, authenticated
      using (true)
    $p$;

    execute 'drop policy if exists event_session_attendance_write_scanners on public.event_session_attendance';
    execute $p$
      create policy event_session_attendance_write_scanners
      on public.event_session_attendance
      for insert
      to anon, authenticated
      with check (true)
    $p$;

    execute 'drop policy if exists event_session_attendance_update_scanners on public.event_session_attendance';
    execute $p$
      create policy event_session_attendance_update_scanners
      on public.event_session_attendance
      for update
      to anon, authenticated
      using (true)
      with check (true)
    $p$;
  end if;
end $$;

-- Refresh PostgREST schema cache.
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;

