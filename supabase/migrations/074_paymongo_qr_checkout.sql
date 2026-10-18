create table if not exists public.event_payment_checkouts (
  id uuid primary key,
  event_id uuid not null references public.events(id) on delete cascade,
  student_id uuid not null references public.users(id) on delete cascade,
  amount numeric(12, 2) not null check (amount >= 1),
  currency text not null default 'PHP' check (currency = 'PHP'),
  status text not null default 'creating'
    check (status in ('creating', 'pending', 'processing', 'paid', 'failed', 'expired', 'registration_failed')),
  payment_intent_id text unique,
  payment_method_id text,
  payment_id text,
  qr_image_url text,
  expires_at timestamptz,
  paid_at timestamptz,
  registration_id uuid references public.event_registrations(id) on delete set null,
  failure_reason text,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create unique index if not exists event_payment_checkouts_one_active_idx
  on public.event_payment_checkouts (event_id, student_id)
  where status in ('creating', 'pending', 'processing');

create index if not exists event_payment_checkouts_student_recent_idx
  on public.event_payment_checkouts (student_id, event_id, created_at desc);

create or replace function public.reserve_event_payment_checkout(
  p_checkout_id uuid,
  p_event_id uuid,
  p_student_id uuid,
  p_amount numeric
)
returns void
language plpgsql
security definer
set search_path = public
as $$
declare
  event_row record;
  session_row record;
  session_limit integer;
  reservation_count integer;
  eligible_count integer := 0;
begin
  select id, status, is_free_event, event_fee, event_for, registration_limit
    into event_row
  from public.events
  where id = p_event_id
  for update;

  if not found then
    raise exception 'Event not found';
  end if;
  if event_row.status <> 'published' or coalesce(event_row.is_free_event, true) then
    raise exception 'Event is not available for paid registration';
  end if;
  if round(coalesce(event_row.event_fee, 0)::numeric, 2) <> round(p_amount, 2) then
    raise exception 'Payment amount does not match the event fee';
  end if;
  if exists (
    select 1 from public.event_registrations
    where event_id = p_event_id and student_id = p_student_id
  ) then
    raise exception 'Student is already registered';
  end if;

  if exists (select 1 from public.event_sessions where event_id = p_event_id) then
    if not public.student_matches_event_target(p_student_id, event_row.event_for) then
      raise exception 'Student is not in this event target';
    end if;

    for session_row in
      select id, target_participant, registration_limit
      from public.event_sessions
      where event_id = p_event_id
    loop
      if public.student_matches_event_target(
        p_student_id,
        coalesce(nullif(trim(session_row.target_participant), ''), event_row.event_for)
      ) then
        eligible_count := eligible_count + 1;
        session_limit := coalesce(session_row.registration_limit, event_row.registration_limit);
        if session_limit is not null then
          select
            (select count(*)::integer
             from public.event_session_registrations esr
             where esr.session_id = session_row.id)
            +
            (select count(distinct c.student_id)::integer
             from public.event_payment_checkouts c
             where c.event_id = p_event_id
               and c.student_id <> p_student_id
               and (
                 (c.status = 'creating' and c.created_at > now() - interval '2 minutes')
                 or (c.status = 'pending' and c.expires_at > now())
                 or (c.status = 'processing' and c.updated_at > now() - interval '1 day')
               )
               and public.student_matches_event_target(
                 c.student_id,
                 coalesce(nullif(trim(session_row.target_participant), ''), event_row.event_for)
               )
               and not exists (
                 select 1 from public.event_registrations r
                 where r.event_id = p_event_id and r.student_id = c.student_id
               ))
            into reservation_count;

          if reservation_count >= session_limit then
            raise exception 'Registration is full for the student''s assigned seminar';
          end if;
        end if;
      end if;
    end loop;

    if eligible_count = 0 then
      raise exception 'Student is not assigned to a seminar session';
    end if;
  elsif event_row.registration_limit is not null then
    select
      (select count(*)::integer
       from public.event_registrations r
       where r.event_id = p_event_id)
      +
      (select count(distinct c.student_id)::integer
       from public.event_payment_checkouts c
       where c.event_id = p_event_id
         and c.student_id <> p_student_id
         and (
           (c.status = 'creating' and c.created_at > now() - interval '2 minutes')
           or (c.status = 'pending' and c.expires_at > now())
           or (c.status = 'processing' and c.updated_at > now() - interval '1 day')
         )
         and not exists (
           select 1 from public.event_registrations r
           where r.event_id = p_event_id and r.student_id = c.student_id
         ))
      into reservation_count;

    if reservation_count >= event_row.registration_limit then
      raise exception 'Registration is full for this event';
    end if;
  end if;

  insert into public.event_payment_checkouts (
    id, event_id, student_id, amount, currency, status, created_at, updated_at
  ) values (
    p_checkout_id, p_event_id, p_student_id, round(p_amount, 2), 'PHP', 'creating', now(), now()
  );
end;
$$;

revoke all on function public.reserve_event_payment_checkout(uuid, uuid, uuid, numeric) from public, anon, authenticated;
grant execute on function public.reserve_event_payment_checkout(uuid, uuid, uuid, numeric) to service_role;

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
    select
      (select count(*)::integer from public.event_registrations where event_id = new.event_id)
      +
      (select count(distinct c.student_id)::integer
       from public.event_payment_checkouts c
       where c.event_id = new.event_id
         and c.student_id <> new.student_id
         and (
           (c.status = 'creating' and c.created_at > now() - interval '2 minutes')
           or (c.status = 'pending' and c.expires_at > now())
           or (c.status = 'processing' and c.updated_at > now() - interval '1 day')
         )
         and not exists (
           select 1 from public.event_registrations r
           where r.event_id = new.event_id and r.student_id = c.student_id
         ))
      into reg_count;
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
        select
          (select count(*)::integer
           from public.event_session_registrations
           where session_id = session_row.id)
          +
          (select count(distinct c.student_id)::integer
           from public.event_payment_checkouts c
           where c.event_id = new.event_id
             and c.student_id <> new.student_id
             and (
               (c.status = 'creating' and c.created_at > now() - interval '2 minutes')
               or (c.status = 'pending' and c.expires_at > now())
               or (c.status = 'processing' and c.updated_at > now() - interval '1 day')
             )
             and public.student_matches_event_target(
               c.student_id,
               coalesce(nullif(trim(session_row.target_participant), ''), parent_target)
             )
             and not exists (
               select 1 from public.event_registrations r
               where r.event_id = new.event_id and r.student_id = c.student_id
             ))
          into reg_count;
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

alter table public.event_payment_checkouts enable row level security;
revoke all on public.event_payment_checkouts from anon, authenticated;
grant all on public.event_payment_checkouts to service_role;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception when others then
  null;
end $$;
