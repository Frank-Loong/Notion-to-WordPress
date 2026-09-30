# Content compatibility

This matrix describes the content produced by `Content_Converter` and verified
against WordPress Classic HTML, Gutenberg serialization, and WordPress
`parse_blocks()`.

## Compatibility levels

| Level | Meaning |
| --- | --- |
| Full | Content and its essential semantics are retained. |
| Preserved | Text and nested content are retained, but the original Notion interaction or layout is flattened. |
| Placeholder | The block position is retained, but WordPress cannot reconstruct the Notion-specific data. |

## Block matrix

| Level | Notion block types |
| --- | --- |
| Full | `paragraph`, `heading_1`–`heading_4`, `bulleted_list_item`, `numbered_list_item`, `to_do`, `toggle`, `quote`, `code`, `callout`, `divider`, `bookmark`, `equation`, `table`, `table_row`, `image`, `video`, `audio`, `file`, `pdf`, `embed`, `synced_block` |
| Preserved | `column_list`, `column`, `template`, `tab`, `meeting_notes`, `transcription`, `child_page`, `child_database`, `link_to_page` |
| Placeholder | `breadcrumb`, `table_of_contents`, `unsupported` |
| Safe fallback | Any unknown future block becomes an escaped HTML diagnostic comment and does not abort the page import. |

## Known differences from Notion

- Inline rich-text annotations and colors are retained. Block-level colors are
  not yet applied consistently to every block.
- Toggle children are retained. Toggleable heading children are retained, but
  the heading itself is rendered as a normal heading instead of a disclosure.
- Tabs, columns, templates, and meeting-note sections keep their readable
  nested content, but do not reproduce Notion's editing interactions.
- Breadcrumbs and table-of-contents blocks are semantic placeholders because
  WordPress and Notion do not share the same page hierarchy or heading index.
- Link-to-page and child-page blocks do not yet resolve a Notion page ID to the
  corresponding WordPress permalink in every import order.

## Version policy

The connector currently sends `Notion-Version: 2022-06-28`. New response block
types are handled defensively, but changing the request version is a separate
migration because newer Notion API versions introduce data sources and other
breaking changes.

Automated coverage lives in:

- `tests/fixtures/notion/core-content-blocks.json`
- `tests/fixtures/notion/current-content-blocks.json`
- `tests/unit/ContentConverterSnapshotTest.php`
- `tests/unit/CurrentContentCompatibilityTest.php`
- `tests/integration/wordpress-runtime.php content-fixture`
