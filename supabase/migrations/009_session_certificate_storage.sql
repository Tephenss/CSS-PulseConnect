-- CCS PulseConnect migration 009
-- Ensure seminar certificate templates and certificates exist (session-based).

create table if not exists public.event_session_certificate_templates (
  id uuid primary key default gen_random_uuid(),
  session_id uuid not null unique references public.event_sessions(id) on delete cascade,
  title text not null default 'Certificate of Participation',
  body_text text not null default 'This certifies that {{name}} participated in {{session}}.',
  footer_text text,
  canvas_state jsonb,
  thumbnail_url text,
  created_by uuid references public.users(id) on delete set null,
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create table if not exists public.event_session_certificates (
  id uuid primary key default gen_random_uuid(),
  session_id uuid not null references public.event_sessions(id) on delete cascade,
  student_id uuid not null references public.users(id) on delete cascade,
  certificate_code text not null unique,
  issued_at timestamptz not null default now(),
  issued_by uuid references public.users(id) on delete set null,
  unique (session_id, student_id)
);

create index if not exists event_session_certificate_templates_session_idx
  on public.event_session_certificate_templates(session_id);

create index if not exists event_session_certificates_session_idx
  on public.event_session_certificates(session_id);

create index if not exists event_session_certificates_student_idx
  on public.event_session_certificates(student_id);

grant all privileges on table public.event_session_certificate_templates to anon, authenticated, service_role;
grant all privileges on table public.event_session_certificates to anon, authenticated, service_role;

-- Refresh PostgREST schema cache so the new tables are visible immediately.
do $$
begin
  perform pg_notify('pgrst', 'reload schema');
exception
  when others then
    null;
end $$;

