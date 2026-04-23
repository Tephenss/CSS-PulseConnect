-- CCS PulseConnect migration 019
-- Server-side backfill for seminar-based absences using server time.
-- Called by the app when loading Participants so "No record" becomes "Absent"
-- once the scan window closes.

do $$
begin
  if to_regclass('public.event_sessions') is null
     or to_regclass('public.event_session_attendance') is null
     or to_regclass('public.event_registrations') is null
     or to_regclass('public.tickets') is null then
    return;
  end if;
end $$;

create or replace function public.sync_closed_session_absences(p_event_id uuid)
returns void
language plpgsql
security definer
set search_path = public
as $$
begin
  if p_event_id is null then
    return;
  end if;

  -- Insert absent rows for any closed session where no row exists yet.
  insert into public.event_session_attendance (
    session_id,
    registration_id,
    ticket_id,
    status,
    check_in_at,
    last_scanned_at
  )
  select
    s.id as session_id,
    r.id as registration_id,
    t.id as ticket_id,
    'absent' as status,
    null::timestamptz as check_in_at,
    now() as last_scanned_at
  from public.event_sessions s
  join public.event_registrations r on r.event_id = s.event_id
  join public.tickets t on t.registration_id = r.id
  where s.event_id = p_event_id
    and now() > (s.start_at + make_interval(mins => greatest(coalesce(s.scan_window_minutes, 30), 1)))
  on conflict (session_id, ticket_id) do update
  set
    status = 'absent',
    last_scanned_at = excluded.last_scanned_at,
    check_in_at = null
  where lower(coalesce(public.event_session_attendance.status, '')) not in ('present','scanned','late','early');

exception
  when undefined_table then
    null;
end;
$$;

grant execute on function public.sync_closed_session_absences(uuid) to anon, authenticated;

-- Refresh PostgREST schema cache.
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;

