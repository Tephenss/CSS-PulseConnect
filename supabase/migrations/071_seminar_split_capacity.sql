-- Split a single event registration budget between its two targeted seminars.

alter table public.event_sessions
  add column if not exists registration_limit integer;

alter table public.event_sessions
  drop constraint if exists event_sessions_registration_limit_check;

alter table public.event_sessions
  add constraint event_sessions_registration_limit_check
  check (registration_limit is null or registration_limit between 1 and 9999);

create or replace function public.validate_event_session_capacity_budget()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  target_event_id uuid;
  event_limit integer;
  session_count integer;
  allocated_count integer;
  allocated_total integer;
  over_capacity boolean;
begin
  target_event_id := case when tg_op = 'DELETE' then old.event_id else new.event_id end;
  if target_event_id is null then
    return null;
  end if;

  select registration_limit into event_limit
  from public.events
  where id = target_event_id;

  select count(*)::integer,
         count(registration_limit)::integer,
         coalesce(sum(registration_limit), 0)::integer
  into session_count, allocated_count, allocated_total
  from public.event_sessions
  where event_id = target_event_id;

  if allocated_count > 0 then
    if event_limit is null then
      raise exception 'Set a total Student Limit before assigning separate seminar limits'
        using errcode = 'P0001';
    end if;
    if session_count > 1 and allocated_count <> session_count then
      raise exception 'Set a student limit for both seminars before saving the split allocation'
        using errcode = 'P0001';
    end if;
    if allocated_total > event_limit then
      raise exception 'The two seminar limits cannot exceed the total Student Limit'
        using errcode = 'P0001';
    end if;
  end if;

  select exists (
    select 1
    from public.event_sessions s
    left join public.event_session_registrations r on r.session_id = s.id
    where s.event_id = target_event_id
      and coalesce(s.registration_limit, event_limit) is not null
    group by s.id, s.registration_limit
    having count(r.registration_id) > coalesce(s.registration_limit, event_limit)
  ) into over_capacity;
  if over_capacity then
    raise exception 'A seminar limit cannot be lower than its current registration count'
      using errcode = 'P0001';
  end if;

  return null;
end;
$$;

revoke all on function public.validate_event_session_capacity_budget() from public, anon, authenticated;

drop trigger if exists event_sessions_capacity_budget_trigger on public.event_sessions;
create constraint trigger event_sessions_capacity_budget_trigger
  after insert or update or delete on public.event_sessions
  deferrable initially deferred
  for each row execute function public.validate_event_session_capacity_budget();

create or replace function public.enforce_event_registration_limit()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  reg_limit integer;
  reg_count integer;
  parent_target text;
  session_row record;
  eligible_count integer := 0;
  session_limit integer;
begin
  select registration_limit, event_for
    into reg_limit, parent_target
  from public.events
  where id = new.event_id
  for update;

  if not exists (select 1 from public.event_sessions where event_id = new.event_id) then
    if reg_limit is null then return new; end if;
    select count(*)::integer into reg_count
    from public.event_registrations where event_id = new.event_id;
    if reg_count >= reg_limit then
      raise exception 'Registration is full for this event' using errcode = 'P0001';
    end if;
    return new;
  end if;

  if not public.student_matches_event_target(new.student_id, parent_target) then
    raise exception 'Student is not in this event target' using errcode = 'P0001';
  end if;

  for session_row in
    select id, target_participant, registration_limit
    from public.event_sessions
    where event_id = new.event_id
  loop
    if public.student_matches_event_target(
      new.student_id,
      coalesce(nullif(trim(session_row.target_participant), ''), parent_target)
    ) then
      eligible_count := eligible_count + 1;
      session_limit := coalesce(session_row.registration_limit, reg_limit);
      if session_limit is not null then
        select count(*)::integer into reg_count
        from public.event_session_registrations
        where session_id = session_row.id;
        if reg_count >= session_limit then
          raise exception 'Registration is full for your assigned seminar' using errcode = 'P0001';
        end if;
      end if;
    end if;
  end loop;

  if eligible_count = 0 then
    raise exception 'Student is not assigned to a seminar session' using errcode = 'P0001';
  end if;
  return new;
end;
$$;

create or replace function public.close_event_registration_at_capacity()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  reg_limit integer;
  reg_count integer;
  all_sessions_full boolean;
begin
  select registration_limit into reg_limit from public.events where id = new.event_id;

  if exists (select 1 from public.event_sessions where event_id = new.event_id) then
    select coalesce(bool_and(
             session_limit is not null and session_count >= session_limit
           ), false)
      into all_sessions_full
    from (
      select coalesce(s.registration_limit, reg_limit) as session_limit,
             count(r.registration_id)::integer as session_count
      from public.event_sessions s
      left join public.event_session_registrations r on r.session_id = s.id
      where s.event_id = new.event_id
      group by s.id, s.registration_limit
    ) counts;
    if all_sessions_full then
      update public.events set allow_registration = false, updated_at = now()
      where id = new.event_id;
    end if;
    return new;
  end if;

  if reg_limit is null then return new; end if;
  select count(*)::integer into reg_count
  from public.event_registrations where event_id = new.event_id;
  if reg_count >= reg_limit then
    update public.events set allow_registration = false, updated_at = now()
    where id = new.event_id;
  end if;
  return new;
end;
$$;

create or replace function public.get_event_registration_snapshot(
  p_event_id uuid,
  p_student_id uuid
)
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
  event_target text;
  session_count integer := 0;
  session_reg_count integer := 0;
  session_reg_limit integer;
  session_is_full boolean := false;
  event_found boolean := false;
  session_capacity jsonb := '[]'::jsonb;
begin
  if p_event_id is null then
    return jsonb_build_object('ok', false, 'error', 'event_id_required');
  end if;

  select registration_limit, coalesce(allow_registration, false),
         coalesce(is_free_event, true), event_for, true
  into reg_limit, allow_reg, is_free, event_target, event_found
  from public.events where id = p_event_id;
  if not coalesce(event_found, false) then
    return jsonb_build_object('ok', false, 'error', 'event_not_found');
  end if;

  if p_student_id is not null
     and exists (select 1 from public.event_sessions where event_id = p_event_id) then
    select count(*)::integer,
           coalesce(max(session_reg_count), 0)::integer,
           min(session_limit),
           coalesce(bool_or(session_limit is not null and session_reg_count >= session_limit), false),
           coalesce(jsonb_agg(jsonb_build_object(
             'session_id', session_id,
             'registration_count', session_reg_count,
             'registration_limit', session_limit,
             'is_full', session_limit is not null and session_reg_count >= session_limit
           )), '[]'::jsonb)
    into session_count, session_reg_count, session_reg_limit, session_is_full, session_capacity
    from (
      select s.id as session_id,
             coalesce(s.registration_limit, reg_limit) as session_limit,
             (select count(*)::integer
              from public.event_session_registrations r
              where r.session_id = s.id) as session_reg_count
      from public.event_sessions s
      where s.event_id = p_event_id
        and public.student_matches_event_target(p_student_id, event_target)
        and public.student_matches_event_target(
          p_student_id,
          coalesce(nullif(trim(s.target_participant), ''), event_target)
        )
    ) eligible;

    return jsonb_build_object(
      'ok', true,
      'registration_count', session_reg_count,
      'registered_count', session_reg_count,
      'registration_limit', session_reg_limit,
      'event_registration_limit', reg_limit,
      'allow_registration', allow_reg,
      'is_free_event', is_free,
      'is_full', session_is_full,
      'session_scoped', true,
      'session_registration_count', session_reg_count,
      'session_registration_limit', session_reg_limit,
      'session_is_full', session_is_full,
      'eligible_session_count', session_count,
      'session_capacity', session_capacity
    );
  end if;

  select count(*)::integer into reg_count
  from public.event_registrations where event_id = p_event_id;
  return jsonb_build_object(
    'ok', true,
    'registration_count', reg_count,
    'registered_count', reg_count,
    'registration_limit', reg_limit,
    'allow_registration', allow_reg,
    'is_free_event', is_free,
    'is_full', reg_limit is not null and reg_count >= reg_limit,
    'session_scoped', false
  );
end;
$$;

revoke all on function public.get_event_registration_snapshot(uuid, uuid) from public;
grant execute on function public.get_event_registration_snapshot(uuid, uuid) to anon, authenticated;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception when others then null;
end $$;
