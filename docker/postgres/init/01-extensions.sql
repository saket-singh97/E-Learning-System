-- Extensions required by the LMS.
-- pgvector backs semantic search over lesson artifacts (S2 onward).
-- pg_trgm backs fuzzy text matching in admin search.
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
