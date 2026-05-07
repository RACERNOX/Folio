ALTER TABLE documents ADD COLUMN slug TEXT;
CREATE UNIQUE INDEX idx_documents_slug ON documents(slug);
