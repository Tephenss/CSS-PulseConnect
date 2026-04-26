-- CCS PulseConnect migration 015
-- One-time backfill: mark missed attendance as absent after closed scan windows.

do $$
begin
  -- Simple event flow:
  -- Update existing event-level attendance rows (session_id is null) that missed scan window.
  if to_regclass('public.attendance') is not null then
    execute $sql$
      update public.attendance a
      set
        status = 'absent',
        last_scanned_at = now()
      from public.tickets t
      join public.event_registrations r on r.id = t.registration_id
      join public.events e on e.id = r.event_id
      where a.ticket_id = t.id
        and a.session_id is null
        and lower(coalesce(e.status, '')) in ('published', 'finished')
        and now() > (e.start_at + make_interval(mins => greatest(coalesce(e.grace_time, 30), 1)))
        and coalesce(a.check_in_at::text, '') = ''
        and lower(coalesce(a.status, '')) not in ('present', 'scanned', 'late', 'early', 'absent')
    $sql$;

    -- Insert missing event-level attendance rows as absent for closed windows.
    execute $sql$
      insert into public.attendance (
        ticket_id,
        session_id,
        status,
        check_in_at,
        last_scanned_at
      )
      select
        t.id as ticket_id,
        null::uuid as session_id,
        'absent' as status,
        null::timestamptz as check_in_at,
        now() as last_scanned_at
      from public.tickets t
      join public.event_registrations r on r.id = t.registration_id
      join public.events e on e.id = r.event_id
      where lower(coalesce(e.status, '')) in ('published', 'finished')
        and now() > (e.start_at + make_interval(mins => greatest(coalesce(e.grace_time, 30), 1)))
        and not exists (
          select 1
          from public.attendance a0
          where a0.ticket_id = t.id
            and a0.session_id is null
        )
    $sql$;
  end if;
exception
  when undefined_table then
    null;
end $$;

do $$
begin
  -- Seminar/session flow:
  if to_regclass('public.event_session_attendance') is not null then
    -- Update existing rows that missed their session scan window.
    execute $sql$
      update public.event_session_attendance esa
      set
        status = 'absent',
        last_scanned_at = now()
      from public.event_sessions s
      where esa.session_id = s.id
        and now() > (s.start_at + make_interval(mins => greatest(coalesce(s.scan_window_minutes, 30), 1)))
        and coalesce(esa.check_in_at::text, '') = ''
        and lower(coalesce(esa.status, '')) not in ('present', 'scanned', 'late', 'early', 'absent')
    $sql$;

    -- Insert missing rows as absent for each registration/ticket in closed session windows.
    execute $sql$
      insert into public.event_session_attendance (
        session_id,
        registration_id,
        ticket_id,
        status,
        last_scanned_at
      )
      select
        s.id as session_id,
        r.id as registration_id,
        t.id as ticket_id,
        'absent' as status,
        now() as last_scanned_at
      from public.event_sessions s
      join public.event_registrations r on r.event_id = s.event_id
      join public.tickets t on t.registration_id = r.id
      where now() > (s.start_at + make_interval(mins => greatest(coalesce(s.scan_window_minutes, 30), 1)))
        and not exists (
          select 1
          from public.event_session_attendance esa0
          where esa0.session_id = s.id
            and (esa0.registration_id = r.id or esa0.ticket_id = t.id)
        )
    $sql$;
  elsif to_regclass('public.attendance') is not null then
    -- Fallback for schemas that store session attendance in public.attendance (session_id is NOT null).
    execute $sql$
      update public.attendance a
      set
        status = 'absent',
        last_scanned_at = now()
      from public.event_sessions s
      where a.session_id = s.id
        and a.session_id is not null
        and now() > (s.start_at + make_interval(mins => greatest(coalesce(s.scan_window_minutes, 30), 1)))
        and coalesce(a.check_in_at::text, '') = ''
        and lower(coalesce(a.status, '')) not in ('present', 'scanned', 'late', 'early', 'absent')
    $sql$;

    execute $sql$
      insert into public.attendance (
        ticket_id,
        session_id,
        status,
        check_in_at,
        last_scanned_at
      )
      select
        t.id as ticket_id,
        s.id as session_id,
        'absent' as status,
        null::timestamptz as check_in_at,
        now() as last_scanned_at
      from public.event_sessions s
      join public.event_registrations r on r.event_id = s.event_id
      join public.tickets t on t.registration_id = r.id
      where now() > (s.start_at + make_interval(mins => greatest(coalesce(s.scan_window_minutes, 30), 1)))
        and not exists (
          select 1
          from public.attendance a0
          where a0.ticket_id = t.id
            and a0.session_id = s.id
        )
    $sql$;
  end if;
exception
  when undefined_table then
    null;
end $$;

-- Refresh PostgREST schema/data cache visibility.
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
