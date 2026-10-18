-- Split-session participants can only be absent from their assigned seminar.
-- Keep the legacy all-seminars behavior for non-split events.

-- Remove false absences created by the former event-wide session sync.
delete from public.event_session_attendance esa
using public.event_sessions s, public.events e
where esa.session_id = s.id
  and s.event_id = e.id
  and e.session_attendance_rule = 'any'
  and lower(coalesce(esa.status, '')) = 'absent'
  and esa.check_in_at is null
  and esa.check_out_at is null
  and esa.last_scanned_by is null
  and not exists (
    select 1
    from public.event_session_registrations esr
    where esr.event_id = s.event_id
      and esr.session_id = s.id
      and esr.registration_id = esa.registration_id
  );

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
  join public.events e on e.id = s.event_id
  join public.event_registrations r on r.event_id = s.event_id
  join public.tickets t on t.registration_id = r.id
  where s.event_id = p_event_id
    and now() > (s.start_at + make_interval(mins => greatest(coalesce(s.scan_window_minutes, 30), 1)))
    and (
      coalesce(e.session_attendance_rule, 'all') <> 'any'
      or exists (
        select 1
        from public.event_session_registrations esr
        where esr.event_id = s.event_id
          and esr.session_id = s.id
          and esr.registration_id = r.id
      )
    )
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

-- Preserve the hardened execute grants established by migration 051.
do $$
begin
  if to_regprocedure('public.sync_closed_session_absences(uuid)') is not null then
    revoke execute on function public.sync_closed_session_absences(uuid) from public, anon, authenticated;
    grant execute on function public.sync_closed_session_absences(uuid) to service_role;
  end if;
end $$;

notify pgrst, 'reload schema';
