-- Preserve issued certificates and reusable library templates when an event is hard-deleted.
-- Issued rows keep title snapshots; FKs switch from CASCADE to SET NULL.

-- ---------------------------------------------------------------------------
-- certificate_templates.event_id: keep row, clear link
-- ---------------------------------------------------------------------------
do $$
declare
  fk_name text;
begin
  select con.conname into fk_name
  from pg_constraint con
  join pg_class rel on rel.oid = con.conrelid
  join pg_namespace nsp on nsp.oid = rel.relnamespace
  where nsp.nspname = 'public'
    and rel.relname = 'certificate_templates'
    and con.contype = 'f'
    and pg_get_constraintdef(con.oid) ilike '%event_id%references%events%';

  if fk_name is not null then
    execute format('alter table public.certificate_templates drop constraint %I', fk_name);
  end if;

  alter table public.certificate_templates
    alter column event_id drop not null;

  alter table public.certificate_templates
    add constraint certificate_templates_event_id_fkey
    foreign key (event_id) references public.events(id) on delete set null;
exception
  when duplicate_object then
    null;
end $$;

-- ---------------------------------------------------------------------------
-- certificates.event_id: keep issued cert, clear event link + snapshots
-- ---------------------------------------------------------------------------
do $$
declare
  fk_name text;
  uq_name text;
begin
  select con.conname into fk_name
  from pg_constraint con
  join pg_class rel on rel.oid = con.conrelid
  join pg_namespace nsp on nsp.oid = rel.relnamespace
  where nsp.nspname = 'public'
    and rel.relname = 'certificates'
    and con.contype = 'f'
    and pg_get_constraintdef(con.oid) ilike '%event_id%references%events%';

  if fk_name is not null then
    execute format('alter table public.certificates drop constraint %I', fk_name);
  end if;

  -- Unique (event_id, student_id) breaks when event_id is nullable; use partial unique.
  select con.conname into uq_name
  from pg_constraint con
  join pg_class rel on rel.oid = con.conrelid
  join pg_namespace nsp on nsp.oid = rel.relnamespace
  where nsp.nspname = 'public'
    and rel.relname = 'certificates'
    and con.contype = 'u'
    and pg_get_constraintdef(con.oid) ilike '%event_id%'
    and pg_get_constraintdef(con.oid) ilike '%student_id%';

  if uq_name is not null then
    execute format('alter table public.certificates drop constraint %I', uq_name);
  end if;

  alter table public.certificates
    alter column event_id drop not null;

  alter table public.certificates
    add constraint certificates_event_id_fkey
    foreign key (event_id) references public.events(id) on delete set null;

  create unique index if not exists certificates_event_student_uidx
    on public.certificates(event_id, student_id)
    where event_id is not null;
exception
  when duplicate_object then
    null;
end $$;

alter table public.certificates
  add column if not exists event_title text;

alter table public.certificates
  add column if not exists session_title text;

-- ---------------------------------------------------------------------------
-- event_session_certificates.session_id: keep issued cert after session delete
-- ---------------------------------------------------------------------------
do $$
declare
  fk_name text;
  uq_name text;
begin
  if not exists (
    select 1
    from information_schema.tables
    where table_schema = 'public' and table_name = 'event_session_certificates'
  ) then
    return;
  end if;

  select con.conname into fk_name
  from pg_constraint con
  join pg_class rel on rel.oid = con.conrelid
  join pg_namespace nsp on nsp.oid = rel.relnamespace
  where nsp.nspname = 'public'
    and rel.relname = 'event_session_certificates'
    and con.contype = 'f'
    and pg_get_constraintdef(con.oid) ilike '%session_id%references%event_sessions%';

  if fk_name is not null then
    execute format('alter table public.event_session_certificates drop constraint %I', fk_name);
  end if;

  select con.conname into uq_name
  from pg_constraint con
  join pg_class rel on rel.oid = con.conrelid
  join pg_namespace nsp on nsp.oid = rel.relnamespace
  where nsp.nspname = 'public'
    and rel.relname = 'event_session_certificates'
    and con.contype = 'u'
    and pg_get_constraintdef(con.oid) ilike '%session_id%'
    and pg_get_constraintdef(con.oid) ilike '%student_id%';

  if uq_name is not null then
    execute format('alter table public.event_session_certificates drop constraint %I', uq_name);
  end if;

  alter table public.event_session_certificates
    alter column session_id drop not null;

  alter table public.event_session_certificates
    add constraint event_session_certificates_session_id_fkey
    foreign key (session_id) references public.event_sessions(id) on delete set null;

  create unique index if not exists event_session_certificates_session_student_uidx
    on public.event_session_certificates(session_id, student_id)
    where session_id is not null;
exception
  when duplicate_object then
    null;
end $$;

alter table public.event_session_certificates
  add column if not exists event_id uuid references public.events(id) on delete set null;

alter table public.event_session_certificates
  add column if not exists event_title text;

alter table public.event_session_certificates
  add column if not exists session_title text;

create index if not exists event_session_certificates_event_idx
  on public.event_session_certificates(event_id);

-- ---------------------------------------------------------------------------
-- Seminar template designs: survive session/event delete (canvas still viewable)
-- ---------------------------------------------------------------------------
do $$
declare
  fk_name text;
  uq_name text;
begin
  if not exists (
    select 1
    from information_schema.tables
    where table_schema = 'public' and table_name = 'event_session_certificate_templates'
  ) then
    return;
  end if;

  select con.conname into fk_name
  from pg_constraint con
  join pg_class rel on rel.oid = con.conrelid
  join pg_namespace nsp on nsp.oid = rel.relnamespace
  where nsp.nspname = 'public'
    and rel.relname = 'event_session_certificate_templates'
    and con.contype = 'f'
    and pg_get_constraintdef(con.oid) ilike '%session_id%references%event_sessions%';

  if fk_name is not null then
    execute format(
      'alter table public.event_session_certificate_templates drop constraint %I',
      fk_name
    );
  end if;

  select con.conname into uq_name
  from pg_constraint con
  join pg_class rel on rel.oid = con.conrelid
  join pg_namespace nsp on nsp.oid = rel.relnamespace
  where nsp.nspname = 'public'
    and rel.relname = 'event_session_certificate_templates'
    and con.contype = 'u'
    and pg_get_constraintdef(con.oid) ilike '%session_id%';

  if uq_name is not null then
    execute format(
      'alter table public.event_session_certificate_templates drop constraint %I',
      uq_name
    );
  end if;

  alter table public.event_session_certificate_templates
    alter column session_id drop not null;

  alter table public.event_session_certificate_templates
    add constraint event_session_certificate_templates_session_id_fkey
    foreign key (session_id) references public.event_sessions(id) on delete set null;

  create unique index if not exists event_session_certificate_templates_session_uidx
    on public.event_session_certificate_templates(session_id)
    where session_id is not null;
exception
  when duplicate_object then
    null;
end $$;

-- ---------------------------------------------------------------------------
-- Backfill snapshots from live joins
-- ---------------------------------------------------------------------------
update public.certificates c
set event_title = coalesce(nullif(trim(c.event_title), ''), e.title)
from public.events e
where c.event_id = e.id
  and (c.event_title is null or trim(c.event_title) = '');

update public.event_session_certificates esc
set
  event_id = coalesce(esc.event_id, s.event_id),
  event_title = coalesce(nullif(trim(esc.event_title), ''), e.title),
  session_title = coalesce(
    nullif(trim(esc.session_title), ''),
    nullif(trim(s.topic), ''),
    nullif(trim(s.title), '')
  )
from public.event_sessions s
left join public.events e on e.id = s.event_id
where esc.session_id = s.id;

-- Refresh PostgREST schema cache
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
