# 🚀 Enterprise RAG Assistant (Laravel + PostgreSQL + pgvector)

![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-%23316192.svg?style=for-the-badge&logo=postgresql&logoColor=white)
![pgvector](https://img.shields.io/badge/pgvector-336791?style=for-the-badge&logo=postgresql&logoColor=white)
![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white)
![Ollama](https://img.shields.io/badge/Ollama-White?style=for-the-badge&logo=ollama&logoColor=black)
![Google Gemini](https://img.shields.io/badge/Google_Gemini-8E75B2?style=for-the-badge&logo=google-gemini&logoColor=white)

---

## 📌 Executive Summary

A hardened, enterprise-grade Retrieval-Augmented Generation (RAG) system built with **Laravel**, **PostgreSQL (`pgvector` + `tsvector`)**, and dual LLM support (**Ollama** local inference and **Google Gemini Flash** cloud inference).

Unlike basic tutorial implementations that suffer from hallucinations, context fragmentation, and token exhaustion, this application incorporates industry-standard retrieval mechanics, conversational memory optimizations, dynamic token scaling, and strict source citation grounding.

---

## 🏗️ End-to-End System Architecture

```text
                                  ┌─────────────────────────────┐
                                  │      User Input Query        │
                                  └──────────────┬───────────────┘
                                                  │
                        ┌─────────────────────────┴─────────────────────────┐
                        ▼                                                   ▼
        ┌───────────────────────────────┐                   ┌───────────────────────────────┐
        │  Pruned Sliding Window Memory  │                   │   Metadata Filter Extraction   │
        │  (Last 4 turns, 250-char cap)  │                   │  (e.g., Category: HR, Tech)    │
        └───────────────┬────────────────┘                   └───────────────┬────────────────┘
                         │                                                   │
                         └─────────────────────────┬─────────────────────────┘
                                                     │
                                                     ▼
                                        ┌───────────────────────┐
                                        │      Hybrid Search     │
                                        └───────────┬────────────┘
                                                     │
                        ┌─────────────────────────────┴─────────────────────────┐
                        ▼                                                       ▼
        ┌───────────────────────────────┐                       ┌───────────────────────────────┐
        │        Dense Retrieval        │                       │        Sparse Retrieval        │
        │      (pgvector Cosine)        │                       │    (PostgreSQL tsvector FTS)   │
        │        [Top 6 matches]        │                       │        [Top 2 matches]         │
        └───────────────┬────────────────┘                       └───────────────┬────────────────┘
                         │                                                       │
                         └─────────────────────────┬─────────────────────────────┘
                                                     │
                                                     ▼
                                    ┌─────────────────────────────────┐
                                    │     Prioritized Deduplication    │
                                    │   (Unique Chunks, Max 6 Cap)     │
                                    └────────────────┬──────────────────┘
                                                      │
                                                      ▼
                                    ┌─────────────────────────────────┐
                                    │  Context-Proportional Sizing     │
                                    │ (Auto-scale maxOutputTokens)     │
                                    └────────────────┬──────────────────┘
                                                      │
                                                      ▼
                                    ┌─────────────────────────────────┐
                                    │  Intent-Aware Prompt Assembly    │
                                    │  (Rules for Def / Steps / List)  │
                                    └────────────────┬──────────────────┘
                                                      │
                                                      ▼
                                    ┌─────────────────────────────────┐
                                    │    LLM Inference Execution       │
                                    │   (Gemini Flash / Ollama phi3)   │
                                    └────────────────┬──────────────────┘
                                                      │
                                                      ▼
                                    ┌─────────────────────────────────┐
                                    │ Anti-Hallucination Citation Prune│
                                    │  (Strip unreferenced sources)    │
                                    └────────────────┬──────────────────┘
                                                      │
                                                      ▼
                                    ┌─────────────────────────────────┐
                                    │   Typewriter UI & Concurrency    │
                                    │  State Lock Release on Complete  │
                                    └───────────────────────────────────┘
```

---

## 🛠️ Live URL (Gemini Only) : https://laravel-rag-app.onrender.com/
## 🛠️ Deep Dive: Production Challenges Solved

### 1. Hybrid Search (Dense + Sparse Retrieval Fusion)

**The Problem:** Dense vector embeddings excel at semantic concepts but miss exact alphanumeric keywords, acronyms, and codes. Conversely, naive keyword queries can pollute context windows with irrelevant headers.

**The Engineering Fix:** Implemented a prioritized fusion model:
- Dense vector search handles the primary semantic search (Top 6 matches).
- PostgreSQL `tsvector` with a GIN index acts as a secondary keyword boost (Top 2 matches).
- Results are merged and deduplicated, guaranteeing procedural steps are not crowded out by metadata headers.

### 2. Pruned Sliding Window Memory

**The Problem:** Passing entire conversation histories into subsequent RAG prompts re-injects stale document chunks, causing severe context bloat, token exhaustion, and high latency.

**The Engineering Fix:**
- Extracted the last 4 chat messages (2 conversation turns) prior to query execution.
- Applied an aggressive 250-character truncation filter to previous assistant outputs.
- Resolved pronouns ("it", "its", "these steps") without blowing the prompt token budget or requiring a second LLM query-rewriting call.

### 3. Intent-Aware System Prompting (Task-Specific Extraction)

**The Problem:** Generic system prompts cause models to dump full document chunks regardless of whether the user asked for a simple definition or an entire manual.

**The Engineering Fix:** Implemented a three-tiered extraction rule system:
- **Definition Queries** (*"What is X?"*): Strict concise summary, suppressing recipes/steps.
- **Component Queries** (*"What are its ingredients?"*): Bulleted component lists only.
- **Procedural Queries** (*"How do I prepare X?"*): Full, exhaustive step-by-step instructions.

### 4. Context-Proportional Dynamic Token Sizing

**The Problem:** Hardcoded token limits either truncate long procedural guides mid-sentence or waste execution windows on brief definitions.

**The Engineering Fix:** Output token budgets scale dynamically based on the byte length of the retrieved context:
- Context **> 2000 characters** → `maxOutputTokens = 2048`
- Context **> 800 characters** → `maxOutputTokens = 1024`
- Context **≤ 800 characters** → `maxOutputTokens = 512`

### 5. Post-Generation Source Verification & Refusal Handling

**The Problem:** RAG engines often attach source citations even when the LLM refuses to answer due to missing document context, resulting in citation hallucination.

**The Engineering Fix:**
- Programmatically evaluated the generated text against standard refusal patterns.
- Cross-referenced document titles against the actual generated response body to ensure only utilized files appear on the UI citation badge.

### 6. Client-Side Concurrency Locking

**The Problem:** Rapid user submissions during active typewriter streaming triggered overlapping fetch requests and corrupted local UI state.

**The Engineering Fix:** Implemented explicit UI locking (`lockInputs()`) that keeps send buttons and inputs disabled until the asynchronous typewriter completion callback (`onComplete`) fires.

---

## 🗄️ Database Schema & Storage Design

### `rag_documents`

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT (PK) | Auto-incrementing primary key |
| `title` | VARCHAR(255) | File name or document title |
| `category` | VARCHAR(100) | Classification category (e.g., General, Tech, HR, Finance) |
| `file_path` | VARCHAR(255) | Local disk or cloud storage path |
| `created_at` / `updated_at` | TIMESTAMP | Standard Laravel timestamps |

### `document_chunks`

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT (PK) | Auto-incrementing primary key |
| `rag_document_id` | BIGINT (FK) | Foreign key referencing `rag_documents.id` (onDelete cascade) |
| `chunk_index` | INT | Sequential index position of chunk within the document |
| `content` | TEXT | Raw parsed text content of the chunk |
| `embedding` | vector(768) | pgvector dense embedding column |
| `search_vector` | tsvector | PostgreSQL Full-Text Search sparse vector index (GIN) |

### `rag_messages`

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT (PK) | Auto-incrementing primary key |
| `role` | VARCHAR(50) | Message author (`user` or `assistant`) |
| `content` | TEXT | Conversation payload text |
| `source_info` | TEXT (Nullable) | Formatted, verified source badge string |
| `raw_chunks` | JSON (Nullable) | Stored chunks powering the context inspector drawer |

---

## 📋 Enterprise Operational Pillars (Pre-Deployment Checklist)

Before scaling to multi-tenant production traffic, verify against the four operational pillars:

- [x] **Core Retrieval & Fusion:** Hybrid search (pgvector + tsvector) with deduplication.
- [x] **Context Optimization:** Pruned sliding window memory + dynamic token scaling.
- [x] **Citation Integrity:** Post-generation attribution verification.
- [x] **UI Concurrency Protection:** Lock management during streaming/typewriter output.
- [ ] **Ingestion Resilience:** Offloading PDF parsing and chunk embedding to background queues via Laravel Horizon / Redis; SHA-256 deduplication.
- [ ] **Multi-Tenancy & RBAC:** Row-level document isolation (`tenant_id`, department scopes).
- [ ] **Observability & Evaluation:** Latency telemetry (OpenLIT / LangSmith) and RAG Triad evaluation (Context Relevance, Groundedness, Answer Relevance).
- [ ] **Index & Connection Optimization:** HNSW index creation on pgvector and connection pooling via PgBouncer.

---

## ⚡ Getting Started

### 1. Prerequisites

- PHP 8.2+
- Composer
- Node.js & NPM
- PostgreSQL 15+ with pgvector extension installed
- Ollama (optional, for local inference: `ollama run phi3:mini`)

### 2. Installation

```bash
# Clone the repository
git clone https://github.com/yourusername/laravel-rag-assistant.git
cd laravel-rag-assistant

# Install backend dependencies
composer install

# Install frontend assets
npm install && npm run build
```

### 3. Environment Configuration

Create your `.env` file:

```env
APP_NAME="Laravel RAG Assistant"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=laravel_rag_app
DB_USERNAME=postgres
DB_PASSWORD=your_password

GEMINI_API_KEY=your_gemini_api_key
```

### 4. Database Setup

```bash
# Run migrations to build pgvector and tsvector schemas
php artisan migrate:fresh --seed
```

### 5. Run the Application

```bash
php artisan serve
```
