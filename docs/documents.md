# Tree documents and semantic search

Open **Documents** from a person’s card or the empty-tree screen. The toolbar
switches between all and personal documents and between tiles and a compact list.
In a person's card, **All** includes that person's documents and shared tree
documents, matching the button's counter; other people's personal documents are excluded.
Choose **Add** to select one TXT or Markdown file, enter an optional name and
description, then choose **Upload**. Files added from a person’s card are personal;
files added from an empty tree are shared. After upload, use the **Shared / Personal**
switch on the tile or list row to change the attachment.

Choose the pencil on a document to read its contents, see its attachment and
indexing status, or edit its name, description and attachment. Saving keeps
the document open. Changing the name or description queues reindexing. Closing it
returns to the list. If an upload, edit or deletion conflicts with a change in
another tab, the dialog loads the current data automatically. The form keeps your
draft and shows the saved value of any field edited in both tabs so you can review
it before submitting again. Downloads use the original filename.

Original UTF-8 bytes, including a possible BOM and original line endings, stay
unchanged in private Flysystem document storage. Documents have their own paths
and lifecycle, without media objects or image variants. PostgreSQL holds the attachment,
optional name and description, checksum, original filename/MIME and server-measured `originalSizeBytes`.
Files are limited to 10 MiB each. Downloads remain available if indexing fails.
Deleting a person makes their documents shared; deleting a tree queues cleanup
of all its documents. Soft deletion immediately excludes a document from search.

Docker Compose starts the document worker. On the first upload, the worker creates the Qdrant collection and its payload indexes.

The separate model-init step pulls `qwen3-embedding:0.6b` into `document_models`.
The configured collection uses 1024-dimensional Cosine vectors. The initial pull
needs network access; indexing/query embeddings then run locally. Persistent
volumes also retain Qdrant data and RabbitMQ messages. These services expose no
public host ports. Back up PostgreSQL and the media volume together; vectors
can be rebuilt from originals. Changing the embedding model or dimensions
requires a new collection and reindexing every document.

The native Symfony AI pipeline uses TXT/Markdown loaders, a
`TextSplitTransformer` (1000 characters, overlap 200), vectorizer and Qdrant
store. Only search queries receive the model's retrieval instruction; document
chunks do not. Stable UUIDv5 chunk IDs include the document generation.
`SimilaritySearch` uses a server-scoped `RetrieverInterface`: PostgreSQL selects
live document generations, Qdrant filters them before nearest-neighbour search,
and a batched ORM check validates results again. Each search returns at most six
fragments, with current names/attachments and protected download links. Native
Symfony AI sources persist from the native `SourceCollection` independently of
the answer text. They describe the documents returned by `SimilaritySearch`.
Answer text is neither parsed for citations nor rewritten to connect sources.
The chat shows filenames and authenticated download links, without passage previews.
Text in documents cannot authorize
tree mutations. The implementation follows the
[Symfony RAG cookbook](https://symfony.com/doc/current/ai/cookbook/rag-implementation.html).

RabbitMQ receives jobs directly after the document transaction commits. Publisher
confirms, durable messages, three exponential retries and the
`document_failures` transport are configured. There is deliberately no custom
outbox or jobs table: a crash between commit and publish remains an accepted
gap until the framework's outbox is adopted. Failed publishing records an error;
the original remains downloadable. There is no document retry endpoint, UI action
or custom command. Published failed messages use Messenger's native failure queue.
Workers serialize processing per document and tolerate redelivery,
generation changes and deletion during indexing. Cleanup is repeatable.

```sh
docker compose exec -T backend php bin/console messenger:stats documents document_failures
docker compose exec -T backend php bin/console messenger:failed:show --transport=document_failures
docker compose exec -T backend php bin/console messenger:failed:retry --transport=document_failures
docker compose logs --tail=100 document-worker
```

An interrupted publication cannot be recovered through the failure queue because
RabbitMQ never accepted that message. This gap is accepted until native outbox support.

## Store choice

The following compares every store in the current
[Symfony AI catalogue](https://symfony.com/doc/current/ai/components/store.html#supported-stores)
for this deployment. These are deployment tradeoffs, not benchmarks; only the
Qdrant bridge is installed.

| Store | Assessment for this project |
| --- | --- |
| Azure AI Search | Adds a cloud account and external index lifecycle. |
| ChromaDB | Alternative local vector service; another client dependency. |
| ClickHouse | Would add a second SQL/analytics system. |
| Cloudflare Vectorize | Adds a managed cloud dependency. |
| Doctrine | Reuses ORM; would need a separate evaluation of filtered vector search. |
| Elasticsearch | Adds a search cluster beyond present needs. |
| InMemory | Test/development option; data must fit PHP memory. |
| Symfony Cache | Test/development option; also loads vectors into PHP memory. |
| ManticoreSearch | Another search server to operate. |
| MariaDB | Replaces/adds SQL infrastructure; needs 11.7+. |
| Meilisearch | Another search engine to operate. |
| Milvus | Alternative dedicated vector service; more infrastructure choices. |
| MongoDB Atlas | Adds document-database infrastructure. |
| Neo4j | Adds graph-database infrastructure. |
| OpenSearch | Adds a search cluster. |
| Pinecone | Adds a managed vector service. |
| PostgreSQL/pgvector | Strong alternative: shared server, but requires installing pgvector in the DB image. |
| Qdrant | Selected: local service, native bridge, filtered queries and stable point IDs. |
| Redis Stack | Adds Redis plus vector-search operations. |
| S3 Vectors | Adds AWS account/bucket lifecycle. |
| SQLite | Adds a second persistence engine/file lifecycle. |
| Supabase | Adds another platform around Postgres. |
| SurrealDB | Adds a separate database. |
| Typesense | Adds a separate search engine. |
| Vektor | Embedded PHP/file storage; would need concurrency/filter evaluation here. |
| Weaviate | Alternative dedicated vector service. |

Postgres remains authoritative for ownership, attachment, status and generation;
Qdrant stores only searchable chunks. Filtered deletion and payload-index setup
use Qdrant's HTTP API because the installed bridge removes points by ID. No
application repository accesses DBAL directly. See also the
[Vektor project](https://github.com/centamiv/vektor) for its embedded-storage model.

PDF/OCR are deferred. A future PDF loader should implement `LoaderInterface`
around `pdftotext` text-layer extraction, validate the extracted text, then reuse
this pipeline. Scanned PDFs will need a separately scoped OCR decision. No PDF
classes, binaries or unused dependencies are installed now.

The reproducible large-corpus acceptance record and actual native tool results
are in [the manual RAG report](qa/document-rag-2026-11-09.md).
The [revision acceptance report](qa/document-revision-2026-11-09.md) records
the fixed dialog, view switches and independent storage. The
[source metadata follow-up](qa/document-source-metadata-2026-11-09.md) records
the removal of answer-dependent source selection.
The [document controls follow-up](qa/document-controls-2026-11-09.md) covers
the internal switch labels, shared/personal upload target, compact buttons and
stable toolbar during loading.
The [attachment controls follow-up](qa/document-attachment-2026-11-10.md)
records per-document Shared/Personal switching, removal of the upload target
control and full-width tiles/rows without a reserved scrollbar gutter.
