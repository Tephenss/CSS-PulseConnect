-- CCS PulseConnect migration 060
-- Separate email OTP purposes: signup vs login (and web_login).
-- Fixes: registration OTP cooldown/reuse blocking a fresh login OTP.

alter table public.email_verification_codes
  add column if not exists purpose text not null default 'login';

update public.email_verification_codes
set purpose = 'login'
where purpose is null or btrim(purpose) = '';

do $$
declare
  pk_name text;
begin
  select c.conname into pk_name
  from pg_constraint c
  join pg_class t on t.oid = c.conrelid
  join pg_namespace n on n.oid = t.relnamespace
  where n.nspname = 'public'
    and t.relname = 'email_verification_codes'
    and c.contype = 'p'
  limit 1;

  if pk_name is not null then
    execute format('alter table public.email_verification_codes drop constraint %I', pk_name);
  end if;
exception
  when others then
    null;
end $$;

-- One active code per user+purpose.
do $$
begin
  if not exists (
    select 1
    from pg_constraint c
    join pg_class t on t.oid = c.conrelid
    join pg_namespace n on n.oid = t.relnamespace
    where n.nspname = 'public'
      and t.relname = 'email_verification_codes'
      and c.contype = 'p'
  ) then
    alter table public.email_verification_codes
      add primary key (user_id, purpose);
  end if;
exception
  when others then
    null;
end $$;

create index if not exists email_verification_codes_user_purpose_idx
  on public.email_verification_codes (user_id, purpose);

do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;
