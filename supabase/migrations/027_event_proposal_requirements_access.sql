-- Ensure the mobile app can read/write proposal requirement workflow tables.
-- The app uses the public Supabase client, so these tables must not be hidden
-- behind row-level policies that only the web service key can bypass.

alter table if exists public.event_proposal_requirements disable row level security;
alter table if exists public.event_proposal_documents disable row level security;

grant all privileges on table public.event_proposal_requirements to anon, authenticated, service_role;
grant all privileges on table public.event_proposal_documents to anon, authenticated, service_role;
