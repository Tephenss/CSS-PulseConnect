-- CCS PulseConnect migration 035
-- Backfill open registration for published free events that were not synced on publish.

do $$
begin
  if to_regclass('public.events') is null
     or to_regclass('public.event_registrations') is null then
    return;
  end if;
end $$;

update public.events e
set allow_registration = true,
    updated_at = now()
where e.status = 'published'
  and coalesce(e.is_free_event, true) = true
  and coalesce(e.allow_registration, false) = false
  and (
    e.registration_limit is null
    or (
      select count(*)::integer
      from public.event_registrations r
      where r.event_id = e.id
    ) < e.registration_limit
  );
