-- CCS PulseConnect migration 020
-- Read-only RPC for app-side seminar attendance fetching.
-- This keeps app behavior fetch-only (no auto-absent writes in app).

create or replace function public.get_event_session_attendance_snapshot(p_event_id uuid)
returns table (
  session_id uuid,
  registration_id uuid,
  ticket_id uuid,
  status text,
  check_in_at timestamptz,
  last_scanned_at timestamptz
)
language sql
security definer
set search_path = public
as $$
  select
    esa.session_id,
    esa.registration_id,
    esa.ticket_id,
    esa.status,
    esa.check_in_at,
    esa.last_scanned_at
  from public.event_session_attendance esa
  join public.event_sessions s on s.id = esa.session_id
  where s.event_id = p_event_id
$$;

grant execute on function public.get_event_session_attendance_snapshot(uuid) to anon, authenticated;

-- Refresh PostgREST schema cache.
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;

