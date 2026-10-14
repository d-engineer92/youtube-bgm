CREATE ROLE bgm_test LOGIN PASSWORD 'test-only' NOSUPERUSER NOCREATEDB NOCREATEROLE;
ALTER DATABASE youtube_bgm_test OWNER TO bgm_test;
COMMENT ON DATABASE youtube_bgm_test IS 'youtube-bgm-disposable-test-only-v1';
REVOKE CONNECT ON DATABASE postgres FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO bgm_test;
