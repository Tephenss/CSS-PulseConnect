-- CCS PulseConnect migration 022
-- Student app registration review workflow.

alter table public.users
  add column if not exists account_status text;

alter table public.users
  add column if not exists approval_note text;

alter table public.users
  add column if not exists reviewed_at timestamptz;

alter table public.users
  add column if not exists reviewed_by uuid references public.users(id) on delete set null;

alter table public.users
  add column if not exists registration_source text;

update public.users
set account_status = coalesce(account_status, 'approved')
where account_status is null;

update public.users
set registration_source = coalesce(registration_source, 'web')
where registration_source is null;

do $$
begin
  if not exists (
    select 1
    from pg_constraint
    where conname = 'users_account_status_check'
  ) then
    alter table public.users
      add constraint users_account_status_check
      check (account_status in ('pending', 'approved', 'rejected'));
  end if;
exception
  when duplicate_object then
    null;
end $$;

do $$
begin
  if not exists (
    select 1
    from pg_constraint
    where conname = 'users_registration_source_check'
  ) then
    alter table public.users
      add constraint users_registration_source_check
      check (registration_source in ('web', 'app', 'admin'));
  end if;
exception
  when duplicate_object then
    null;
end $$;

create index if not exists users_account_status_idx on public.users(account_status);
create index if not exists users_registration_source_idx on public.users(registration_source);

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;

