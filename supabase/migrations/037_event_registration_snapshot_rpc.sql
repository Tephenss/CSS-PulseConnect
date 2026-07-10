-- CCS PulseConnect migration 037
-- Single RPC for mobile/web clients to read accurate registration capacity under RLS.

do $$
begin
  if to_regclass('public.events') is null
     or to_regclass('public.event_registrations') is null then
    return;
  end if;
end $$;

create or replace function public.get_event_registration_snapshot(p_event_id uuid)
returns jsonb
language plpgsql
security definer
stable
set search_path = public
as $$
declare
  reg_count integer := 0;
  reg_limit integer;
  allow_reg boolean := false;
  is_free boolean := true;
  event_found boolean := false;
begin
  if p_event_id is null then
    return jsonb_build_object('ok', false, 'error', 'event_id_required');
  end if;

  select
    registration_limit,
    coalesce(allow_registration, false),
    coalesce(is_free_event, true),
    true
  into reg_limit, allow_reg, is_free, event_found
  from public.events
  where id = p_event_id;

  if not coalesce(event_found, false) then
    return jsonb_build_object('ok', false, 'error', 'event_not_found');
  end if;

  select count(*)::integer
    into reg_count
  from public.event_registrations
  where event_id = p_event_id;

  return jsonb_build_object(
    'ok', true,
    'registration_count', reg_count,
    'registered_count', reg_count,
    'registration_limit', reg_limit,
    'allow_registration', allow_reg,
    'is_free_event', is_free,
    'is_full', reg_limit is not null and reg_count >= reg_limit
  );
end;
$$;

grant execute on function public.get_event_registration_snapshot(uuid) to anon, authenticated;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
