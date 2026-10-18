-- Seminar registrations have their own participant scopes and capacity counts.

create table if not exists public.event_session_registrations (
  event_id uuid not null references public.events(id) on delete cascade,
  session_id uuid not null references public.event_sessions(id) on delete cascade,
  registration_id uuid not null references public.event_registrations(id) on delete cascade,
  student_id uuid not null references public.users(id) on delete cascade,
  created_at timestamptz not null default now(),
  primary key (session_id, registration_id)
);

create index if not exists event_session_registrations_event_idx
  on public.event_session_registrations(event_id, session_id);
create index if not exists event_session_registrations_student_idx
  on public.event_session_registrations(student_id, event_id);

alter table public.event_session_registrations enable row level security;
revoke all on table public.event_session_registrations from public, anon, authenticated;
grant all on table public.event_session_registrations to service_role;

create or replace function public.student_matches_event_target(
  p_student_id uuid,
  p_target text
)
returns boolean
language plpgsql
security definer
stable
set search_path = public
as $$
declare
  target_value text := upper(trim(coalesce(p_target, '')));
  target_course text;
  target_years text;
  target_remainder text;
  student_course text := '';
  student_specialization text := '';
  student_year text := '';
  program_value text := '';
  section_value text := '';
  user_course text := '';
  roster_course_code text := '';
  year_match text[];
  encoded_match text[];
  legacy_match text[];
begin
  if p_student_id is null then
    return false;
  end if;

  select
    upper(trim(coalesce(r.program_label, ''))),
    upper(trim(coalesce(s.name, ''))),
    upper(trim(coalesce(u.course, ''))),
    upper(trim(coalesce(r.course_code, ''))),
    coalesce(r.year_level::text, '')
  into program_value, section_value, user_course, roster_course_code, student_year
  from public.users u
  left join public.student_roster r
    on r.user_id = u.id and r.archived_at is null
  left join public.sections s on s.id = r.section_id
  where u.id = p_student_id
    and u.archived_at is null
  order by r.updated_at desc nulls last
  limit 1;

  if not found then
    return false;
  end if;

  if program_value ~ 'BSIT' or section_value ~ 'BSIT'
     or user_course in ('IT', 'BSIT') or roster_course_code = 'IT' then
    student_course := 'BSIT';
  elsif program_value ~ 'BSCS' or section_value ~ 'BSCS'
     or user_course in ('CS', 'BSCS') or roster_course_code = 'CS' then
    student_course := 'BSCS';
  end if;

  if program_value ~ '(^|[^A-Z])SD([^A-Z]|$)' or section_value ~ '(^|[^A-Z])SD([^A-Z]|$)' then
    student_specialization := 'SD';
  elsif program_value ~ '(^|[^A-Z])BA([^A-Z]|$)' or section_value ~ '(^|[^A-Z])BA([^A-Z]|$)' then
    student_specialization := 'BA';
  end if;

  if student_year not in ('1', '2', '3', '4') then
    student_year := '';
    year_match := regexp_match(section_value, '(^|[^0-9])([1-4])([^0-9]|$)');
    if year_match is not null then
      student_year := year_match[2];
    end if;
  end if;

  if target_value in ('', 'ALL', 'ALL LEVELS', 'NONE', 'ALL STUDENTS', 'ALL COURSES', 'ALL COURSES - ALL LEVELS') then
    return true;
  end if;

  encoded_match := regexp_match(
    target_value,
    '^COURSE\s*=\s*(ALL|BSIT-SD|BSIT-BA|BSIT|BSCS|IT|CS)\s*;\s*YEARS\s*=\s*([0-9,\sA-Z]+)$'
  );
  if encoded_match is not null then
    target_course := encoded_match[1];
    target_years := regexp_replace(encoded_match[2], '\s+', '', 'g');
  else
    legacy_match := regexp_match(
      target_value,
      '^(ALL(?:\s+COURSES)?|BSIT(?:[\s_-]*(?:SD|BA))?|BSCS|IT|CS)\s*[-:]\s*(.+)$'
    );
    if legacy_match is not null then
      target_course := regexp_replace(legacy_match[1], '[\s_]+', '-', 'g');
      if target_course = 'ALL-COURSES' then target_course := 'ALL'; end if;
      target_remainder := legacy_match[2];
    else
      target_course := regexp_replace(target_value, '[\s_]+', '-', 'g');
    end if;
  end if;

  if target_course = 'IT' then target_course := 'BSIT'; end if;
  if target_course = 'CS' then target_course := 'BSCS'; end if;

  if target_course in ('BSIT-SD', 'BSIT-BA') then
    if student_course <> 'BSIT' or student_specialization <> split_part(target_course, '-', 2) then
      return false;
    end if;
  elsif target_course = 'BSIT' then
    if student_course <> 'BSIT' then return false; end if;
  elsif target_course = 'BSCS' then
    if student_course <> 'BSCS' then return false; end if;
  elsif target_course <> 'ALL' then
    if target_course in ('1', '2', '3', '4') then
      if student_year = '' then return true; end if;
      return student_year = target_course;
    end if;
    return false;
  end if;

  if encoded_match is not null then
    if target_years = 'ALL' or student_year = '' then return true; end if;
    return (',' || target_years || ',') like '%,' || student_year || ',%';
  end if;

  if target_remainder is not null then
    if student_year = '' then return true; end if;
    return target_remainder ~ ('(^|[^0-9])' || student_year || '([^0-9]|$)');
  end if;

  return true;
end;
$$;

revoke all on function public.student_matches_event_target(uuid, text) from public, anon, authenticated;

create or replace function public.assign_registration_to_event_sessions(
  p_event_id uuid,
  p_registration_id uuid,
  p_student_id uuid
)
returns void
language plpgsql
security definer
set search_path = public
as $$
declare
  parent_target text;
  session_row record;
  session_target text;
begin
  select event_for into parent_target
  from public.events where id = p_event_id;

  if not public.student_matches_event_target(p_student_id, parent_target) then
    return;
  end if;

  for session_row in
    select id, target_participant
    from public.event_sessions
    where event_id = p_event_id
  loop
    session_target := coalesce(nullif(trim(session_row.target_participant), ''), parent_target);
    if public.student_matches_event_target(p_student_id, session_target) then
      insert into public.event_session_registrations(event_id, session_id, registration_id, student_id)
      values (p_event_id, session_row.id, p_registration_id, p_student_id)
      on conflict (session_id, registration_id) do update
        set event_id = excluded.event_id,
            student_id = excluded.student_id;
    end if;
  end loop;
end;
$$;

revoke all on function public.assign_registration_to_event_sessions(uuid, uuid, uuid) from public, anon, authenticated;

create or replace function public.sync_session_registration_assignments()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  registration_row record;
  parent_target text;
  session_target text;
begin
  if tg_op = 'UPDATE' and old.event_id is distinct from new.event_id then
    delete from public.event_session_registrations where session_id = old.id;
  elsif tg_op = 'UPDATE' then
    delete from public.event_session_registrations where session_id = new.id;
  end if;

  select event_for into parent_target from public.events where id = new.event_id;
  session_target := coalesce(nullif(trim(new.target_participant), ''), parent_target);

  for registration_row in
    select id, student_id
    from public.event_registrations
    where event_id = new.event_id
  loop
    if public.student_matches_event_target(registration_row.student_id, parent_target)
       and public.student_matches_event_target(registration_row.student_id, session_target) then
      insert into public.event_session_registrations(event_id, session_id, registration_id, student_id)
      values (new.event_id, new.id, registration_row.id, registration_row.student_id)
      on conflict (session_id, registration_id) do nothing;
    end if;
  end loop;

  return new;
end;
$$;

revoke all on function public.sync_session_registration_assignments() from public, anon, authenticated;

drop trigger if exists event_sessions_sync_registration_assignments_trigger on public.event_sessions;
create trigger event_sessions_sync_registration_assignments_trigger
  after insert or update of target_participant, event_id on public.event_sessions
  for each row execute function public.sync_session_registration_assignments();

create or replace function public.refresh_event_session_registration_assignments()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  registration_row record;
begin
  delete from public.event_session_registrations where event_id = new.id;
  for registration_row in
    select id, student_id from public.event_registrations where event_id = new.id
  loop
    perform public.assign_registration_to_event_sessions(new.id, registration_row.id, registration_row.student_id);
  end loop;
  return new;
end;
$$;

revoke all on function public.refresh_event_session_registration_assignments() from public, anon, authenticated;

drop trigger if exists events_refresh_session_registration_assignments_trigger on public.events;
create trigger events_refresh_session_registration_assignments_trigger
  after update of event_for on public.events
  for each row when (old.event_for is distinct from new.event_for)
  execute function public.refresh_event_session_registration_assignments();

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
    select id, target_participant
    from public.event_sessions
    where event_id = new.event_id
  loop
    if public.student_matches_event_target(
      new.student_id,
      coalesce(nullif(trim(session_row.target_participant), ''), parent_target)
    ) then
      eligible_count := eligible_count + 1;
      if reg_limit is not null then
        select count(*)::integer into reg_count
        from public.event_session_registrations
        where session_id = session_row.id;
        if reg_count >= reg_limit then
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

create or replace function public.assign_event_registration_to_sessions()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
  if tg_op = 'UPDATE' then
    delete from public.event_session_registrations
    where registration_id = old.id;
  end if;
  perform public.assign_registration_to_event_sessions(new.event_id, new.id, new.student_id);
  return new;
end;
$$;

revoke all on function public.assign_event_registration_to_sessions() from public, anon, authenticated;

drop trigger if exists event_registrations_limit_trigger on public.event_registrations;
create trigger event_registrations_limit_trigger
  before insert on public.event_registrations
  for each row execute function public.enforce_event_registration_limit();

drop trigger if exists event_registrations_assign_sessions_trigger on public.event_registrations;
create trigger event_registrations_assign_sessions_trigger
  after insert or update of event_id, student_id on public.event_registrations
  for each row execute function public.assign_event_registration_to_sessions();

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
  if reg_limit is null then return new; end if;

  if exists (select 1 from public.event_sessions where event_id = new.event_id) then
    select coalesce(bool_and(session_count >= reg_limit), false)
      into all_sessions_full
    from (
      select count(r.registration_id)::integer as session_count
      from public.event_sessions s
      left join public.event_session_registrations r on r.session_id = s.id
      where s.event_id = new.event_id
      group by s.id
    ) counts;
    if all_sessions_full then
      update public.events set allow_registration = false, updated_at = now()
      where id = new.event_id;
    end if;
    return new;
  end if;

  select count(*)::integer into reg_count
  from public.event_registrations where event_id = new.event_id;
  if reg_count >= reg_limit then
    update public.events set allow_registration = false, updated_at = now()
    where id = new.event_id;
  end if;
  return new;
end;
$$;

drop trigger if exists event_registrations_close_at_capacity_trigger on public.event_registrations;
create trigger event_registrations_close_at_capacity_trigger
  after insert on public.event_registrations
  for each row execute function public.close_event_registration_at_capacity();

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
  session_is_full boolean := false;
  event_found boolean := false;
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
           coalesce(bool_or(reg_limit is not null and session_reg_count >= reg_limit), false)
    into session_count, session_reg_count, session_is_full
    from (
      select (
        select count(*)::integer
        from public.event_session_registrations r
        where r.session_id = s.id
      ) as session_reg_count
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
      'registration_limit', reg_limit,
      'allow_registration', allow_reg,
      'is_free_event', is_free,
      'is_full', session_is_full,
      'session_scoped', true,
      'session_registration_count', session_reg_count,
      'session_registration_limit', reg_limit,
      'session_is_full', session_is_full,
      'eligible_session_count', session_count
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

-- Preserve correct counts for registrations that existed before this migration.
do $$
declare
  registration_row record;
begin
  for registration_row in
    select id, event_id, student_id from public.event_registrations
  loop
    perform public.assign_registration_to_event_sessions(
      registration_row.event_id,
      registration_row.id,
      registration_row.student_id
    );
  end loop;
end;
$$;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception when others then null;
end $$;
