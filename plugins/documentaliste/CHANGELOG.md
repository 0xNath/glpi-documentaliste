# Changelog

All notable changes to this project will be documented in this file.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `analyzer_version` column: deduplication only reuses results of the current analyzer, outdated results are re-analyzed.
- Sensitive values (passwords, logins, keys, tokens, IBAN, cards) are redacted before keyword extraction.

- GLPI 11.0.x support (in addition to GLPI 12.0.x).
- Local OCR of images with Tesseract 5 (auto-detected; OCR engine recorded in the `model` column).
- `NoNetworkEgressTest`: guarantees no document data can leave the GLPI server.

- `glpi_plugin_documentaliste_descriptions` table, one row per document.
- Documents are queued on add / file replacement, cleaned on purge.
- `analyze` automatic action processing the queue (with sha1sum deduplication).
- Local text extraction: plain text, docx/pptx/odt/odp, xlsx/xls/ods, images metadata.
- `LocalDescriber`: factual summary, keywords, sensitive content detection (no AI).
- `SensitiveContentDetector`: passwords, private keys, API tokens, IBAN, card numbers.
- "Document description" tab on documents and search options on Document.
- `documentaliste_document_described` hook for other plugins.
