-- Least-privilege grants for the ActiveSync (EAS) database role.
--
-- Single source of truth: shipped in the office image
-- (/usr/local/lib/avalynxoffice/eas-grants.sql) and applied by
-- office-entrypoint after every migration run as well as on demand via
-- `office-grant-eas` (used by `core.sh grant-eas` of the Compose stack).
-- Idempotent; a missing role is reported and skipped.
--
-- The target role is taken from the session setting avalynxoffice.eas_role,
-- which the caller sets in the same session right before this block, e.g.
--   SET avalynxoffice.eas_role = '<role>';          (office-grant-eas)
--   SELECT set_config('avalynxoffice.eas_role', :'role', false);  (psql)
-- The office-grant-eas script validates the role name (^[a-z_][a-z0-9_]*$)
-- before passing it through; the DO block additionally quotes it with %I.
--
-- The EAS service maps (Doctrine) or reads (plain SQL) exactly these tables;
-- no blanket "ALL TABLES" grant and no default privileges are used, so new
-- core tables stay invisible to the EAS role until they are listed here.
-- All primary keys are UUIDs (license_state uses a fixed id), therefore no
-- sequence privileges are needed.
DO $$
DECLARE
    r text := current_setting('avalynxoffice.eas_role', true);
    t text;
    -- Tables the EAS service only reads.
    read_only text[] := ARRAY[
        'account', 'user_account', 'license_state', 'app_user_app_password'
    ];
    -- Mail, address book and calendar tables written by the EAS service.
    read_write text[] := ARRAY[
        'email', 'email_folder', 'email_address_link', 'email_body_link', 'email_attachment_link',
        'body', 'attachment', 'address', 'account_address', 'folder', 'account_folder',
        'calendar_event'
    ];
BEGIN
    IF r IS NULL OR r = '' THEN
        RAISE EXCEPTION 'avalynxoffice.eas_role is not set (SELECT set_config(''avalynxoffice.eas_role'', ''<role>'', false))';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
        RAISE NOTICE 'role % does not exist, skipping EAS grants', r;
        RETURN;
    END IF;

    -- Remove the blanket grants of earlier image versions (no-op on fresh
    -- installations) so that only the explicit grants below remain.
    EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON TABLES FROM %I', r);
    EXECUTE format('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE ALL ON SEQUENCES FROM %I', r);
    EXECUTE format('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM %I', r);
    EXECUTE format('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM %I', r);

    EXECUTE format('GRANT USAGE ON SCHEMA public TO %I', r);

    FOREACH t IN ARRAY read_only LOOP
        IF to_regclass('public.' || quote_ident(t)) IS NOT NULL THEN
            EXECUTE format('GRANT SELECT ON TABLE public.%I TO %I', t, r);
        END IF;
    END LOOP;

    FOREACH t IN ARRAY read_write LOOP
        IF to_regclass('public.' || quote_ident(t)) IS NOT NULL THEN
            EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.%I TO %I', t, r);
        END IF;
    END LOOP;

    -- app_user: only the columns the EAS user lookup needs. The password
    -- hash of the web login is deliberately NOT readable (devices
    -- authenticate with app passwords from app_user_app_password).
    IF to_regclass('public.app_user') IS NOT NULL THEN
        EXECUTE format(
            'GRANT SELECT (id, email, roles, active, last_auth_at, created_at, updated_at) ON TABLE public.app_user TO %I', r
        );
        -- Last-authentication timestamp (Active Seat accounting).
        EXECUTE format('GRANT UPDATE (last_auth_at) ON TABLE public.app_user TO %I', r);
    END IF;

    -- Column-restricted updates: inbox folder detection and the
    -- last-use timestamp of an app password.
    IF to_regclass('public.account') IS NOT NULL THEN
        EXECUTE format('GRANT UPDATE (inbox_folder_id) ON TABLE public.account TO %I', r);
    END IF;
    IF to_regclass('public.app_user_app_password') IS NOT NULL THEN
        EXECUTE format('GRANT UPDATE (last_used_at) ON TABLE public.app_user_app_password TO %I', r);
    END IF;
END
$$;
