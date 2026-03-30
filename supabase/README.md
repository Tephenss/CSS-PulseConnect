## Supabase setup (CSS PulseConnect)

### Required config
- `SUPABASE_URL`: your project URL
- `SUPABASE_KEY`: Service Role Key (server-side only)

### Run the schema
Open **Supabase Dashboard → SQL Editor** and run:
- `supabase/migrations/001_pulseconnect_schema.sql`

This creates/extends:
- `public.users` with `role` (`admin|teacher|student`)
- `public.events`, `public.event_registrations`, `public.tickets`, `public.attendance`
- `public.evaluation_questions`, `public.evaluation_answers`
- `public.certificate_templates`, `public.certificates`

