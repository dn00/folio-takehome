UPDATE documents SET readable_id = 'doc-' || id WHERE readable_id IS NULL;
