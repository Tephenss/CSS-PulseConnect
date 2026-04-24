-- CCS PulseConnect migration 021
-- Email verification support for mobile login flow.

alter table public.users
  add column if not exists email_verified boolean not null default false;

alter table public.users
  add column if not exists email_verified_at timestamptz;

create table if not exists public.email_verification_codes (
  user_id uuid primary key references public.users(id) on delete cascade,
  code text not null,
  expires_at timestamptz not null,
  created_at timestamptz not null default now(),
  last_sent_at timestamptz not null default now()
);

create index if not exists email_verification_codes_expires_idx
  on public.email_verification_codes(expires_at);

alter table public.email_verification_codes enable row level security;

do $$
begin
  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'email_verification_codes'
      and policyname = 'email_verification_codes_select'
  ) then
    create policy email_verification_codes_select
      on public.email_verification_codes
      for select
      to anon, authenticated
      using (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'email_verification_codes'
      and policyname = 'email_verification_codes_insert'
  ) then
    create policy email_verification_codes_insert
      on public.email_verification_codes
      for insert
      to anon, authenticated
      with check (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'email_verification_codes'
      and policyname = 'email_verification_codes_update'
  ) then
    create policy email_verification_codes_update
      on public.email_verification_codes
      for update
      to anon, authenticated
      using (true)
      with check (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'email_verification_codes'
      and policyname = 'email_verification_codes_delete'
  ) then
    create policy email_verification_codes_delete
      on public.email_verification_codes
      for delete
      to anon, authenticated
      using (true);
  end if;
end $$;

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
