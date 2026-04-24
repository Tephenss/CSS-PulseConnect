-- CCS PulseConnect migration 023
-- Password reset via email confirmation code.

create table if not exists public.password_reset_codes (
  user_id uuid primary key references public.users(id) on delete cascade,
  code text not null,
  expires_at timestamptz not null,
  verified_at timestamptz,
  reset_token text,
  token_expires_at timestamptz,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create index if not exists password_reset_codes_expires_idx
  on public.password_reset_codes(expires_at);

create index if not exists password_reset_codes_token_expires_idx
  on public.password_reset_codes(token_expires_at);

alter table public.password_reset_codes enable row level security;

do $$
begin
  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'password_reset_codes'
      and policyname = 'password_reset_codes_select'
  ) then
    create policy password_reset_codes_select
      on public.password_reset_codes
      for select
      to anon, authenticated
      using (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'password_reset_codes'
      and policyname = 'password_reset_codes_insert'
  ) then
    create policy password_reset_codes_insert
      on public.password_reset_codes
      for insert
      to anon, authenticated
      with check (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'password_reset_codes'
      and policyname = 'password_reset_codes_update'
  ) then
    create policy password_reset_codes_update
      on public.password_reset_codes
      for update
      to anon, authenticated
      using (true)
      with check (true);
  end if;

  if not exists (
    select 1
    from pg_policies
    where schemaname = 'public'
      and tablename = 'password_reset_codes'
      and policyname = 'password_reset_codes_delete'
  ) then
    create policy password_reset_codes_delete
      on public.password_reset_codes
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

